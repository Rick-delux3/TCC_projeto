<?php

namespace App\Jobs;

use App\Exceptions\LeadLoversRateLimitedException;
use App\Models\Lead;
use App\Services\LeadLoversService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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

    private const STANDARD_FIELDS = [
        'name' => 'Name',
        'phone' => 'Phone',
        'city' => 'City',
        'state' => 'State',
        'company' => 'Company',
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

    public array $requestedFields = [];

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function __construct(
        public int $leadId,
        public string $originalEmail,
        int $syncVersion = 0,
        array $requestedFields = [],
    ) {
        $this->syncVersion = $syncVersion;
        $this->requestedFields = $this->normalizeRequestedFields($requestedFields);
    }

    public function handle(LeadLoversService $leadLoversService): void
    {
        if (! config('services.leadlovers.enabled', false)) {
            $this->updateCurrentVersion([
                'leadlovers_update_status' => 'disabled',
                'leadlovers_update_error' => 'Integração com a LeadLovers desativada.',
            ]);

            return;
        }

        $originalEmail = trim($this->originalEmail);

        if (
            $originalEmail === ''
            || filter_var($originalEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            $this->markFailed(
                'O e-mail original do lead não é válido para a atualização na LeadLovers.'
            );

            Log::warning('Lead sem e-mail original válido para atualização na LeadLovers.', [
                'lead_id' => $this->leadId,
                'sync_version' => $this->syncVersion,
            ]);

            return;
        }

        if (! $this->claimCurrentVersion()) {
            return;
        }

        $lead = Lead::with([
            'endereco',
            'company',
            'despesas',
            'conjuge',
            'imobiliariaInformada',
        ])->find($this->leadId);

        if (
            ! $lead
            || (int) $lead->leadlovers_update_version !== $this->syncVersion
        ) {
            return;
        }

        $requestedFields = $this->normalizeRequestedFields(
            $this->requestedFields
        );

        if ($requestedFields === []) {
            $this->markFailed(
                'O job não identifica quais campos devem ser atualizados na LeadLovers.',
                [
                    'success' => false,
                    'status' => null,
                    'http_status' => null,
                    'requested_fields' => [],
                    'unsupported_fields' => ['legacy_job_without_field_context'],
                ]
            );

            Log::warning('Atualização da LeadLovers interrompida por falta de contexto.', [
                'lead_id' => $this->leadId,
                'sync_version' => $this->syncVersion,
            ]);

            return;
        }

        [$payload, $unsupportedFields] = $this->payloadForRequestedFields(
            $lead,
            $originalEmail,
            $requestedFields
        );

        if ($unsupportedFields !== []) {
            $this->markFailed(
                'Existem campos sem valor ou mapeamento seguro para atualizar na LeadLovers.',
                [
                    'success' => false,
                    'status' => 422,
                    'http_status' => null,
                    'requested_fields' => $requestedFields,
                    'unsupported_fields' => $unsupportedFields,
                ]
            );

            Log::warning('Atualização da LeadLovers interrompida antes do envio.', [
                'lead_id' => $this->leadId,
                'sync_version' => $this->syncVersion,
                'requested_fields' => $requestedFields,
                'unsupported_fields' => $unsupportedFields,
            ]);

            return;
        }

        try {
            $response = $leadLoversService->updateLead($payload);
        } catch (LeadLoversRateLimitedException $exception) {
            if ($this->attempts() >= $this->tries) {
                throw $exception;
            }

            $retryAfter = max(
                1,
                $exception->retryAfter
                    ?? (int) config('services.leadlovers.rate_limit_retry_seconds', 60)
            );

            Log::notice('Atualização do lead devolvida à fila por rate limit.', [
                'lead_id' => $this->leadId,
                'sync_version' => $this->syncVersion,
                'attempt' => $this->attempts(),
                'retry_after' => $retryAfter,
                'cloudflare_1015' => $exception->cloudflareBlocked,
            ]);

            $this->release($retryAfter);

            return;
        }

        if (! ($response['success'] ?? false)) {
            $status = is_numeric($response['status'] ?? null)
                ? (int) $response['status']
                : null;

            if ($this->isRetryableStatus($status)) {
                $responseSummary = $this->responseSummary(
                    $response,
                    $requestedFields
                );

                $this->updateCurrentVersion([
                    'leadlovers_update_response' => $this->encodeSummary(
                        $responseSummary
                    ),
                ], ['processing']);

                Log::warning('Atualização do lead falhou transitoriamente na LeadLovers.', [
                    'lead_id' => $this->leadId,
                    'sync_version' => $this->syncVersion,
                    'attempt' => $this->attempts(),
                    'status' => $status,
                    'http_status' => is_numeric($response['http_status'] ?? null)
                        ? (int) $response['http_status']
                        : null,
                    'payload_fields' => array_keys($payload),
                    'dynamic_fields_count' => count(
                        $payload['DynamicFields'] ?? []
                    ),
                    'requested_fields' => $requestedFields,
                    'response_message' => $responseSummary['response_message']
                        ?? null,
                ]);

                throw new RuntimeException(
                    'Falha transitória ao atualizar o lead na LeadLovers.'
                );
            }

            $this->markFailed(
                'A LeadLovers recusou a atualização.',
                $this->responseSummary($response, $requestedFields)
            );

            return;
        }

        $updated = $this->updateCurrentVersion([
            'leadlovers_update_status' => 'synced',
            'leadlovers_update_error' => null,
            'leadlovers_update_response' => $this->encodeSummary(
                $this->responseSummary($response, $requestedFields)
            ),
            'leadlovers_update_at' => now(),
        ], ['processing']);

        if ($updated === 0) {
            $this->queueLatestVersionForReconciliation();

            return;
        }

        Log::info('Lead atualizado na LeadLovers com sucesso.', [
            'lead_id' => $this->leadId,
            'sync_version' => $this->syncVersion,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $updated = $this->updateCurrentVersion([
            'leadlovers_update_status' => 'failed',
            'leadlovers_update_error' => 'A sincronização com a LeadLovers falhou após as tentativas configuradas.',
        ]);

        if ($updated === 0) {
            return;
        }

        Log::warning('Atualização do lead na LeadLovers esgotou as tentativas.', [
            'lead_id' => $this->leadId,
            'sync_version' => $this->syncVersion,
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    private function claimCurrentVersion(): bool
    {
        $allowedStatuses = $this->attempts() > 1
            ? ['pending', 'processing']
            : ['pending', 'failed'];

        return $this->updateCurrentVersion([
            'leadlovers_update_status' => 'processing',
        ], $allowedStatuses) === 1;
    }

    private function markFailed(
        string $message,
        ?array $responseSummary = null
    ): void {
        $attributes = [
            'leadlovers_update_status' => 'failed',
            'leadlovers_update_error' => $message,
        ];

        if ($responseSummary !== null) {
            $attributes['leadlovers_update_response'] = json_encode(
                $responseSummary,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );
        }

        $this->updateCurrentVersion($attributes);
    }

    private function updateCurrentVersion(
        array $attributes,
        array $allowedStatuses = ['pending', 'processing']
    ): int {
        return Lead::query()
            ->whereKey($this->leadId)
            ->where('leadlovers_update_version', $this->syncVersion)
            ->whereIn('leadlovers_update_status', $allowedStatuses)
            ->update($attributes);
    }

    private function responseSummary(
        array $response,
        array $requestedFields
    ): array {
        $summary = [
            'success' => (bool) ($response['success'] ?? false),
            'status' => is_numeric($response['status'] ?? null)
                ? (int) $response['status']
                : null,
            'http_status' => is_numeric($response['http_status'] ?? null)
                ? (int) $response['http_status']
                : null,
            'requested_fields' => $this->normalizeRequestedFields(
                $requestedFields
            ),
        ];

        if (is_string($response['response_message'] ?? null)) {
            $summary['response_message'] = $response['response_message'];
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

    private function isRetryableStatus(?int $status): bool
    {
        return $status === null
            || in_array($status, [408, 425, 429], true)
            || $status >= 500;
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
            ) {
                return null;
            }

            if (! config('services.leadlovers.enabled', false)) {
                $lead->forceFill([
                    'leadlovers_update_status' => 'disabled',
                    'leadlovers_update_error' => 'Integração com a LeadLovers desativada.',
                ])->saveQuietly();

                return null;
            }

            $originalEmail = trim((string) $lead->email);

            if (
                $originalEmail === ''
                || filter_var($originalEmail, FILTER_VALIDATE_EMAIL) === false
            ) {
                $lead->forceFill([
                    'leadlovers_update_status' => 'failed',
                    'leadlovers_update_error' => 'O e-mail atual não permite reconciliar a atualização na LeadLovers.',
                ])->saveQuietly();

                return null;
            }

            $syncVersion = (int) $lead->leadlovers_update_version + 1;
            $requestedFields = $this->normalizeRequestedFields([
                ...$this->requestedFields,
                ...$this->requestedFieldsFromLead($lead),
            ]);

            if ($requestedFields === []) {
                $lead->forceFill([
                    'leadlovers_update_status' => 'failed',
                    'leadlovers_update_error' => 'A reconciliação não possui contexto seguro dos campos alterados.',
                ])->saveQuietly();

                return null;
            }

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
                'lead_id' => (int) $lead->id,
                'original_email' => $originalEmail,
                'sync_version' => $syncVersion,
                'requested_fields' => $requestedFields,
            ];
        });

        if ($reconciliation === null) {
            return;
        }

        try {
            $job = new self(
                leadId: $reconciliation['lead_id'],
                originalEmail: $reconciliation['original_email'],
                syncVersion: $reconciliation['sync_version'],
                requestedFields: $reconciliation['requested_fields'],
            );
            $job->onQueue('leadlovers')->afterCommit();
            Bus::dispatch($job);
        } catch (Throwable $exception) {
            Lead::query()
                ->whereKey($reconciliation['lead_id'])
                ->where('leadlovers_update_version', $reconciliation['sync_version'])
                ->where('leadlovers_update_status', 'pending')
                ->update([
                    'leadlovers_update_status' => 'failed',
                    'leadlovers_update_error' => 'A reconciliação não pôde ser colocada na fila.',
                ]);

            Log::warning('Falha ao enfileirar reconciliação do lead na LeadLovers.', [
                'lead_id' => $reconciliation['lead_id'],
                'sync_version' => $reconciliation['sync_version'],
                'exception' => $exception::class,
            ]);
        }
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

        if (! is_array($response)) {
            return [];
        }

        return $this->normalizeRequestedFields(
            is_array($response['requested_fields'] ?? null)
                ? $response['requested_fields']
                : []
        );
    }

    private function payloadForRequestedFields(
        Lead $lead,
        string $originalEmail,
        array $requestedFields
    ): array {
        $payload = ['Email' => $originalEmail];
        $unsupportedFields = [];
        $standardValues = [
            'name' => trim((string) $lead->nome),
            'phone' => $this->onlyNumbers($lead->tel),
            'city' => trim((string) $lead->endereco?->cidade_imovel),
            'state' => trim((string) $lead->endereco?->estado),
            'company' => trim((string) (
                $lead->company?->name
                ?? $lead->imobiliariaInformada?->nome_imobiliaria_informada
                ?? $lead->imobiliaria
                ?? ''
            )),
        ];

        foreach ($requestedFields as $field) {
            $payloadField = self::STANDARD_FIELDS[$field] ?? null;

            if ($payloadField === null) {
                continue;
            }

            $value = $standardValues[$field] ?? '';

            if (! filled($value)) {
                $unsupportedFields[] = $field;

                continue;
            }

            $payload[$payloadField] = $value;
        }

        $dynamicFields = $this->dynamicFields(
            $lead,
            $requestedFields,
            $unsupportedFields
        );

        if ($dynamicFields !== []) {
            $payload['DynamicFields'] = $dynamicFields;
        }

        return [
            $payload,
            $this->normalizeRequestedFields($unsupportedFields),
        ];
    }

    private function dynamicFields(
        Lead $lead,
        array $requestedFields,
        array &$unsupportedFields
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
        $dynamicFields = [];

        foreach (array_intersect($requestedFields, self::DYNAMIC_FIELDS) as $field) {
            $fieldId = is_array($fieldIds) ? ($fieldIds[$field] ?? null) : null;
            $value = trim((string) ($values[$field] ?? ''));

            if (
                ! is_numeric($fieldId)
                || (int) $fieldId <= 0
                || ! filled($value)
            ) {
                $unsupportedFields[] = $field;

                continue;
            }

            $dynamicFields[] = [
                'Id' => (int) $fieldId,
                'Value' => $value,
            ];
        }

        return $dynamicFields;
    }

    private function onlyNumbers(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}
