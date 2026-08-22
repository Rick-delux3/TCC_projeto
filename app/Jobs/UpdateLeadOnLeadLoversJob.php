<?php

namespace App\Jobs;

use App\Exceptions\LeadLoversApiException;
use App\Models\Lead;
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversLeadResolver;
use App\Events\DashboardActivityChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[\AllowDynamicProperties]
class UpdateLeadOnLeadLoversJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const UPDATE_FIELDS = [
        'name',
        'phone',
        'city',
        'state',
        'company',
        'cpf',
        'estado_civil',
        'conjuge_cpf',
        'valor_aluguel',
        'valor_agua',
        'valor_luz',
        'valor_gas',
        'valor_condominio',
        'valor_iptu',
        'outras_despesas',
    ];

    private const STATIC_FIELDS = [
        'name',
        'phone',
        'city',
        'state',
        'company',
    ];

    private const DYNAMIC_FIELDS = [
        'cpf',
        'estado_civil',
        'conjuge_cpf',
        'valor_aluguel',
        'valor_agua',
        'valor_luz',
        'valor_gas',
        'valor_condominio',
        'valor_iptu',
        'outras_despesas',
    ];

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public int $syncVersion = 0;

    /** @var array<int, string> */
    public array $requestedFields = [];

    public function __construct(
        public int $leadId,
        int $syncVersion = 0,
        array $requestedFields = [],
    ) {
        $this->syncVersion = $syncVersion;
        $this->requestedFields = $this->normalizeRequestedFields($requestedFields);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('leadlovers:update:'.$this->leadId))
                ->releaseAfter(15)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(
        LeadLoversApiClient $leadLovers,
        LeadLoversLeadResolver $resolver,
    ): void {
        if (! config('services.leadlovers.enabled', false)) {
            $this->updateCurrentVersion([
                'leadlovers_update_status' => 'disabled',
                'leadlovers_update_error' => 'Integracao com a LeadLovers desativada.',
            ], ['pending', 'processing', 'failed']);

            return;
        }

        if (! $this->claimCurrentVersion()) {
            return;
        }

        $lead = $this->loadLead();

        if ($lead === null) {
            return;
        }

        $requestedFields = $this->normalizeRequestedFields($this->requestedFields);

        if ($requestedFields === []) {
            $this->markFailed(
                'O job nao identifica quais campos devem ser atualizados na LeadLovers.',
                $this->failureSummary('local_preflight', null, [], [
                    'legacy_job_without_field_context',
                ])
            );

            return;
        }

        [$payload, $unsupportedFields] = $this->payloadForRequestedFields(
            $lead,
            $requestedFields
        );

        if ($unsupportedFields !== []) {
            $this->markFailed(
                'Existem campos sem valor ou mapeamento seguro para atualizar na LeadLovers.',
                $this->failureSummary(
                    'local_preflight',
                    null,
                    $requestedFields,
                    $unsupportedFields
                )
            );

            Log::warning('Atualizacao LeadLovers bloqueada pela validacao local; nenhum PUT foi enviado.', [
                'lead_id' => $this->leadId,
                'sync_version' => $this->syncVersion,
                'requested_fields' => $requestedFields,
                'unsupported_fields' => $unsupportedFields,
            ]);

            return;
        }

        $remoteLeadId = $resolver->positiveInteger($lead->leadlovers_lead_id);

        if ($remoteLeadId === null) {
            $remoteLeadId = $this->reconcileRemoteLeadId(
                $leadLovers,
                $resolver,
                $lead,
                null,
                $requestedFields
            );

            if ($remoteLeadId === null) {
                return;
            }
        }

        if (! $this->isCurrentProcessing($remoteLeadId)) {
            return;
        }

        try {
            $response = $leadLovers->updateLead($remoteLeadId, $payload);
        } catch (LeadLoversApiException $exception) {
            if (! $this->isLeadNotFound($exception)) {
                $this->handleApiFailure($exception, 'lead_update', $requestedFields);

                return;
            }

            $reconciledId = $this->reconcileRemoteLeadId(
                $leadLovers,
                $resolver,
                $lead,
                $remoteLeadId,
                $requestedFields
            );

            if ($reconciledId === null || ! $this->isCurrentProcessing($reconciledId)) {
                return;
            }

            try {
                $response = $leadLovers->updateLead($reconciledId, $payload);
                $remoteLeadId = $reconciledId;
            } catch (LeadLoversApiException $retryException) {
                $this->handleApiFailure(
                    $retryException,
                    'lead_update_after_reconciliation',
                    $requestedFields
                );

                return;
            }
        }

        $updated = $this->updateCurrentVersion([
            'leadlovers_update_status' => 'synced',
            'leadlovers_update_error' => null,
            'leadlovers_update_response' => $this->encodeSummary([
                'success' => ($response['success'] ?? null) === true,
                'operation' => 'lead_update',
                'remote_lead_id' => $remoteLeadId,
                'requested_fields' => $requestedFields,
            ]),
            'leadlovers_update_at' => now(),
        ], ['processing']);

        if ($updated === 0) {
            $this->queueLatestVersionForReconciliation();

            return;
        }

        Log::info('Lead atualizado na LeadLovers com sucesso.', [
            'lead_id' => $this->leadId,
            'remote_lead_id' => $remoteLeadId,
            'sync_version' => $this->syncVersion,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $updated = $this->updateCurrentVersion([
            'leadlovers_update_status' => 'failed',
            'leadlovers_update_error' => 'A sincronizacao com a LeadLovers falhou apos as tentativas configuradas.',
        ], ['processing']);

        if ($updated !== 1) {
            return;
        }

        Log::warning('Atualizacao do lead na LeadLovers esgotou as tentativas.', [
            'lead_id' => $this->leadId,
            'sync_version' => $this->syncVersion,
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    private function loadLead(): ?Lead
    {
        return Lead::query()
            ->with([
                'endereco',
                'company',
                'despesas',
                'conjuge',
                'imobiliariaInformada',
            ])
            ->whereKey($this->leadId)
            ->where('leadlovers_update_version', $this->syncVersion)
            ->where('leadlovers_update_status', 'processing')
            ->first();
    }

    private function claimCurrentVersion(): bool
    {
        if ($this->syncVersion <= 0) {
            return false;
        }

        $allowedStatuses = $this->attempts() > 1
            ? ['pending', 'processing']
            : ['pending', 'failed'];

        return $this->updateCurrentVersion([
            'leadlovers_update_status' => 'processing',
        ], $allowedStatuses) === 1;
    }

    private function reconcileRemoteLeadId(
        LeadLoversApiClient $leadLovers,
        LeadLoversLeadResolver $resolver,
        Lead $lead,
        ?int $expectedRemoteId,
        array $requestedFields,
    ): ?int {
        $email = $resolver->normalizedEmail($lead->email);

        if ($email === null) {
            $this->markFailed(
                'O e-mail do lead nao permite uma conciliacao segura na LeadLovers.',
                $this->failureSummary('lead_search', null, $requestedFields)
            );

            return null;
        }

        try {
            $result = $leadLovers->searchLeads(
                $resolver->searchPayload($email)
            );
        } catch (LeadLoversApiException $exception) {
            $this->handleApiFailure($exception, 'lead_search', $requestedFields);

            return null;
        }

        $match = $resolver->uniqueExactMatch($result, $email);

        if ($match['outcome'] !== 'matched' || ! is_int($match['lead_id'])) {
            $this->markFailed(
                'A busca remota nao retornou uma correspondencia unica, exata e com leadId.',
                [
                    'success' => false,
                    'operation' => 'lead_search',
                    'search_outcome' => $match['outcome'],
                    'total' => $match['total'],
                    'records' => $match['records'],
                    'requested_fields' => $requestedFields,
                ]
            );

            return null;
        }

        $remoteLeadId = $match['lead_id'];

        if (! $this->persistReconciledId($expectedRemoteId, $remoteLeadId)) {
            return null;
        }

        return $remoteLeadId;
    }

    private function persistReconciledId(
        ?int $expectedRemoteId,
        int $remoteLeadId,
    ): bool {
        if (
            $expectedRemoteId === $remoteLeadId
            && $this->isCurrentProcessing($remoteLeadId)
        ) {
            return true;
        }

        $query = Lead::query()
            ->whereKey($this->leadId)
            ->where('leadlovers_update_version', $this->syncVersion)
            ->where('leadlovers_update_status', 'processing');

        if ($expectedRemoteId === null) {
            $query->whereNull('leadlovers_lead_id');
        } else {
            $query->where('leadlovers_lead_id', $expectedRemoteId);
        }

        $updated = $query->update([
            'leadlovers_lead_id' => $remoteLeadId,
        ]);

        if ($updated === 1) {
            return true;
        }

        return $this->isCurrentProcessing($remoteLeadId);
    }

    private function isCurrentProcessing(?int $remoteLeadId = null): bool
    {
        $query = Lead::query()
            ->whereKey($this->leadId)
            ->where('leadlovers_update_version', $this->syncVersion)
            ->where('leadlovers_update_status', 'processing');

        if ($remoteLeadId !== null) {
            $query->where('leadlovers_lead_id', $remoteLeadId);
        }

        return $query->exists();
    }

    private function handleApiFailure(
        LeadLoversApiException $exception,
        string $operation,
        array $requestedFields,
    ): void {
        $summary = $this->failureSummary(
            $operation,
            $exception,
            $requestedFields
        );

        if (! $exception->isTransient) {
            $message = $exception->statusCode === 401
                ? 'A autenticacao da LeadLovers foi recusada; verifique a configuracao.'
                : 'A LeadLovers recusou a atualizacao do lead.';
            $this->markFailed($message, $summary);

            return;
        }

        $this->updateCurrentVersion([
            'leadlovers_update_response' => $this->encodeSummary($summary),
        ], ['processing']);

        if ($this->attempts() >= $this->tries) {
            $this->markFailed(
                'A atualizacao excedeu as tentativas configuradas.',
                $summary
            );

            return;
        }

        $delay = $exception->retryAfterSeconds
            ?? $this->retryDelay();

        Log::notice('Atualizacao do lead devolvida a fila.', [
            'lead_id' => $this->leadId,
            'sync_version' => $this->syncVersion,
            'operation' => $operation,
            'attempt' => $this->attempts(),
            'retry_after' => $delay,
            'status_code' => $exception->statusCode,
            'error_code' => $exception->errorCode,
        ]);

        $this->release($delay);
    }

    private function retryDelay(): int
    {
        $backoff = $this->backoff();
        $index = min(max(0, $this->attempts() - 1), count($backoff) - 1);
        $maximum = max(
            1,
            (int) config('services.leadlovers.rate_limit_max_retry_seconds', 900)
        );

        return min($maximum, $backoff[$index]);
    }

    private function isLeadNotFound(LeadLoversApiException $exception): bool
    {
        return $exception->statusCode === 404
            && mb_strtoupper((string) $exception->errorCode) === 'LEAD_NOT_FOUND';
    }

    private function markFailed(string $message, ?array $summary = null): void
    {
        $attributes = [
            'leadlovers_update_status' => 'failed',
            'leadlovers_update_error' => $message,
        ];

        if ($summary !== null) {
            $attributes['leadlovers_update_response'] = $this->encodeSummary($summary);
        }

        $this->updateCurrentVersion($attributes, ['processing']);
    }

    private function updateCurrentVersion(
        array $attributes,
        array $allowedStatuses = ['pending', 'processing'],
    ): int {
        $updated = Lead::query()
            ->whereKey($this->leadId)
            ->where('leadlovers_update_version', $this->syncVersion)
            ->whereIn('leadlovers_update_status', $allowedStatuses)
            ->update($attributes);

        if ($updated !== 1) {
        return $updated;
        }

        $change = match ($attributes['leadlovers_update_status'] ?? null) {
            'synced' => 'lead.sync.synced',
            'failed' => 'lead.sync.failed',
            'disabled' => 'lead.sync.disabled',
            default => null,
        };

        if ($change !== null) {
            $this->notifyDashboard($change);
        }

        return $updated;
        
    }

    private function notifyDashboard(string $change): void {

        $lead = Lead::query()
            ->select(['id', 'company_id'])
            ->find($this->leadId);

        if ($lead === null) {
            return;
        }

        $resourceId = (int) $lead->id;

        $companyId = $lead->company_id !== null
                ? (int) $lead->company_id
                : null;

        DashboardActivityChanged::dispatch(
            'lead',
            $resourceId,
            $companyId,
            $change,
        );
    }
    


    private function failureSummary(
        string $operation,
        ?LeadLoversApiException $exception,
        array $requestedFields,
        array $unsupportedFields = [],
    ): array {
        $summary = [
            'success' => false,
            'operation' => $operation,
            'status_code' => $exception?->statusCode,
            'requested_fields' => $this->normalizeRequestedFields(
                $requestedFields
            ),
        ];

        if ($exception?->errorCode !== null) {
            $summary['error_code'] = $exception->errorCode;
        }

        if ($unsupportedFields !== []) {
            $summary['unsupported_fields'] = $this->normalizeRequestedFields(
                $unsupportedFields
            );
        }

        return $summary;
    }

    private function encodeSummary(array $summary): string
    {
        return json_encode(
            $summary,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function payloadForRequestedFields(
        Lead $lead,
        array $requestedFields,
    ): array {
        $payload = [];
        $unsupportedFields = [];
        $staticValues = [
            'name' => $this->nullableString($lead->nome),
            'phone' => $this->nullablePhone($lead->tel),
            'city' => $this->nullableString($lead->endereco?->cidade_imovel),
            'state' => $this->nullableString($lead->endereco?->estado),
            'company' => $this->nullableString(
                $lead->company?->name
                    ?? $lead->imobiliariaInformada?->nome_imobiliaria_informada
                    ?? $lead->imobiliaria
            ),
        ];
        $staticFields = [];

        foreach (array_intersect($requestedFields, self::STATIC_FIELDS) as $field) {
            $staticFields[$field] = $staticValues[$field];
        }

        if ($staticFields !== []) {
            $payload['staticFields'] = $staticFields;
        }

        $dynamicFields = $this->dynamicFields(
            $lead,
            $requestedFields,
            $unsupportedFields
        );

        if ($dynamicFields !== []) {
            $payload['dynamicFields'] = $dynamicFields;
        }

        return [
            $payload,
            $this->normalizeRequestedFields($unsupportedFields),
        ];
    }

    private function dynamicFields(
        Lead $lead,
        array $requestedFields,
        array &$unsupportedFields,
    ): array {
        $values = [
            'cpf' => $lead->cpf,
            'estado_civil' => $lead->estado_civil,
            'conjuge_cpf' => $lead->conjuge?->cpf,
            'valor_aluguel' => $lead->despesas?->valor_aluguel,
            'valor_agua' => $lead->despesas?->valor_agua,
            'valor_luz' => $lead->despesas?->valor_luz,
            'valor_gas' => $lead->despesas?->valor_gas,
            'valor_condominio' => $lead->despesas?->valor_condominio,
            'valor_iptu' => $lead->despesas?->valor_iptu,
            'outras_despesas' => $lead->despesas?->outras_despesas,
        ];
        $fieldIds = config('services.leadlovers.dynamic_fields', []);
        $fields = [];

        foreach (array_intersect($requestedFields, self::DYNAMIC_FIELDS) as $field) {
            $fieldId = is_array($fieldIds)
                ? $this->positiveInteger($fieldIds[$field] ?? null)
                : null;
            $value = $values[$field] ?? null;

            if ($fieldId === null || $value === null) {
                $unsupportedFields[] = $field;

                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                $unsupportedFields[] = $field;

                continue;
            }

            $fields[] = [
                'id' => $fieldId,
                'value' => $value,
            ];
        }

        return $fields;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullablePhone(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $phone = preg_replace('/\D+/', '', (string) $value);

        return $phone === '' ? null : $phone;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9]\d*\z/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    private function normalizeRequestedFields(array $fields): array
    {
        $requested = array_fill_keys(
            array_values(array_filter($fields, 'is_string')),
            true
        );

        return array_values(array_filter(
            self::UPDATE_FIELDS,
            static fn (string $field): bool => isset($requested[$field])
        ));
    }

    private function requestedFieldsFromLead(Lead $lead): array
    {
        $response = $lead->leadlovers_update_response;

        return is_array($response)
            && is_array($response['requested_fields'] ?? null)
                ? $this->normalizeRequestedFields($response['requested_fields'])
                : [];
    }

    private function queueLatestVersionForReconciliation(): void
    {
        $reconciliation = DB::transaction(function (): ?array {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->lockForUpdate()
                ->first();

            if (
                ! $lead
                || (int) $lead->leadlovers_update_version <= $this->syncVersion
                || ! in_array($lead->leadlovers_status, ['sent', 'send'], true)
            ) {
                return null;
            }

            if (! config('services.leadlovers.enabled', false)) {
                $lead->forceFill([
                    'leadlovers_update_status' => 'disabled',
                    'leadlovers_update_error' => 'Integracao com a LeadLovers desativada.',
                ])->saveQuietly();

                $this->notifyDashboard('lead.sync.disabled');

                return null;
            }

            $requestedFields = $this->normalizeRequestedFields([
                ...$this->requestedFields,
                ...$this->requestedFieldsFromLead($lead),
            ]);

            if ($requestedFields === []) {
                return null;
            }

            $syncVersion = (int) $lead->leadlovers_update_version + 1;
            $lead->forceFill([
                'leadlovers_update_status' => 'pending',
                'leadlovers_update_version' => $syncVersion,
                'leadlovers_update_error' => null,
                'leadlovers_update_response' => [
                    'requested_fields' => $requestedFields,
                ],
                'leadlovers_update_requested_at' => now(),
            ])->saveQuietly();

            return [
                'sync_version' => $syncVersion,
                'requested_fields' => $requestedFields,
            ];
        });

        if ($reconciliation === null) {
            return;
        }

        try {
            $job = new self(
                leadId: $this->leadId,
                syncVersion: $reconciliation['sync_version'],
                requestedFields: $reconciliation['requested_fields'],
            );
            $job->onQueue('leadlovers')->afterCommit();
            Bus::dispatch($job);
        } catch (Throwable $exception) {
            $updated = Lead::query()
                ->whereKey($this->leadId)
                ->where(
                    'leadlovers_update_version',
                    $reconciliation['sync_version']
                )
                ->where('leadlovers_update_status', 'pending')
                ->update([
                    'leadlovers_update_status' => 'failed',
                    'leadlovers_update_error' => 'A reconciliacao nao pode ser colocada na fila.',
                ]);
            
            if ($updated === 1) {
                $this->notifyDashboard('lead.sync.failed');
            }
            

            Log::warning('Falha ao enfileirar reconciliacao do lead na LeadLovers.', [
                'lead_id' => $this->leadId,
                'sync_version' => $reconciliation['sync_version'],
                'exception' => $exception::class,
            ]);
        }
    }
}
