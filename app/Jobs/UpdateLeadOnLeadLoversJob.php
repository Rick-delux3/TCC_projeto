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
use Illuminate\Support\Facades\Queue;
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
            $this->disablePendingReadinessHandoff();
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
            $this->recoverPendingReadinessHandoff();

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

            Log::warning('Atualização da LeadLovers bloqueada pela validação local; nenhum PATCH foi enviado.', [
                'lead_id' => $this->leadId,
                'sync_version' => $this->syncVersion,
                'failure_stage' => 'local_preflight',
                'http_request_sent' => false,
                'retryable' => false,
                'requested_fields' => $requestedFields,
                'unsupported_fields' => $unsupportedFields,
            ]);

            return;
        }

        try {
            if (! $this->ensureRemoteLeadIsReady(
                $lead,
                $leadLoversService,
                $originalEmail,
                $requestedFields
            )) {
                return;
            }

            if (! $this->markPatchAttemptStarted($lead)) {
                return;
            }

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
            $responseSummary = $this->preserveProviderDiagnostic(
                $this->responseSummary($response, $requestedFields),
                $lead
            );

            if ($this->isRetryableStatus($status)) {
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
                    'provider_error_classification' => data_get(
                        $responseSummary,
                        'provider_diagnostic.classification'
                    ),
                    'provider_error_fingerprint' => data_get(
                        $responseSummary,
                        'provider_diagnostic.fingerprint'
                    ),
                    'provider_error_from_previous_attempt' => data_get(
                        $responseSummary,
                        'provider_diagnostic.preserved_from_previous_attempt',
                        false
                    ),
                ]);

                throw new RuntimeException(
                    'Falha transitória ao atualizar o lead na LeadLovers.'
                );
            }

            $this->markFailed(
                'A LeadLovers recusou a atualização.',
                $responseSummary
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
        try {
            if (
                $this->recoverPendingReadinessHandoff()
                || $this->restartAfterReadinessConfirmation()
            ) {
                Log::info('Atualização reiniciada após esgotar a fase de confirmação remota.', [
                    'lead_id' => $this->leadId,
                    'previous_sync_version' => $this->syncVersion,
                ]);

                return;
            }
        } catch (Throwable $handoffException) {
            if ($this->markPendingReadinessHandoffFailed()) {
                Log::warning('Falha ao recuperar o handoff da atualização confirmada.', [
                    'lead_id' => $this->leadId,
                    'previous_sync_version' => $this->syncVersion,
                    'exception' => $handoffException::class,
                ]);

                return;
            }
        }

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

        $providerDiagnostic = $this->providerDiagnosticSummary(
            $response['provider_diagnostic'] ?? null
        );

        if ($providerDiagnostic !== null) {
            $summary['provider_diagnostic'] = $providerDiagnostic;
        }

        return $summary;
    }

    private function preserveProviderDiagnostic(
        array $summary,
        Lead $lead
    ): array {
        if (
            isset($summary['provider_diagnostic'])
            || ($summary['status'] ?? null) !== null
            || ($summary['http_status'] ?? null) !== null
        ) {
            return $summary;
        }

        $storedResponse = $lead->leadlovers_update_response;
        $storedDiagnostic = is_array($storedResponse)
            ? $this->providerDiagnosticSummary(
                $storedResponse['provider_diagnostic']
                    ?? $storedResponse['previous_patch_diagnostic']
                    ?? null
            )
            : null;

        if ($storedDiagnostic !== null) {
            $storedDiagnostic['preserved_from_previous_attempt'] = true;
            $summary['provider_diagnostic'] = $storedDiagnostic;
        }

        return $summary;
    }

    private function ensureRemoteLeadIsReady(
        Lead $lead,
        LeadLoversService $leadLoversService,
        string $originalEmail,
        array $requestedFields
    ): bool {
        if ($this->normalizedLeadCode($lead->leadlovers_lead_code) !== null) {
            if ($this->shouldRestartAfterReadinessConfirmation($lead)) {
                $this->restartAfterReadinessConfirmation();

                return false;
            }

            return true;
        }

        $lookup = $leadLoversService->getLeadByEmail($originalEmail);
        $providerStatusData = $this->remoteLeadStatusData($lookup);
        $providerStatuses = $providerStatusData['statuses'];
        $providerStatusInvalid = $providerStatusData['invalid'];
        $providerStatus = collect($providerStatuses)->first(
            static fn (int $status): bool => $status < 200 || $status >= 300
        ) ?? ($providerStatuses[0] ?? null);
        $httpStatus = $this->normalizedStatusCode(
            $lookup['_http_status'] ?? null
        );
        $transportStatus = $httpStatus ?? $providerStatus;
        $identityData = $this->remoteLeadIdentityData($lookup);
        $remoteIdentities = $identityData['identities'];
        $partialIdentityRecords = $identityData['partial_records'];
        $remoteEmailMatches = count($remoteIdentities) === 1
            && hash_equals(
                mb_strtolower($originalEmail),
                $remoteIdentities[0]['email']
            );
        $lookupExplicitlyFailed = $this->lookupBodyExplicitlyFailed($lookup);
        $lookupStatusesAreSuccessful = $transportStatus !== null
            && $transportStatus >= 200
            && $transportStatus < 300
            && ! $providerStatusInvalid
            && collect($providerStatuses)->every(
                static fn (int $status): bool => $status >= 200
                    && $status < 300
            );

        if (
            $lookupStatusesAreSuccessful
            && ! $lookupExplicitlyFailed
            && count($remoteIdentities) === 1
            && $partialIdentityRecords === 0
            && $remoteEmailMatches
        ) {
            $remoteCode = $remoteIdentities[0]['code'];
            $summary = $this->preservePreviousPatchDiagnostic([
                'success' => false,
                'status' => $providerStatus,
                'http_status' => $httpStatus,
                'requested_fields' => $requestedFields,
                'response_message' => 'Lead confirmado remotamente; o PATCH ainda não foi iniciado.',
                'readiness_check' => [
                    'confirmed' => true,
                    'provider_status' => $providerStatus,
                    'provider_status_invalid' => false,
                    'remote_identity_candidates' => 1,
                    'partial_identity_records' => 0,
                    'remote_email_matches' => true,
                    'explicit_failure' => false,
                    'patch_attempt_started' => false,
                ],
            ], $lead);
            $updated = $this->updateCurrentVersion([
                'leadlovers_lead_code' => $remoteCode,
                'leadlovers_update_error' => null,
                'leadlovers_update_response' => $this->encodeSummary($summary),
            ], ['processing']);

            if ($updated === 0) {
                return false;
            }

            $lead->forceFill([
                'leadlovers_lead_code' => $remoteCode,
                'leadlovers_update_error' => null,
                'leadlovers_update_response' => $summary,
            ]);

            Log::info('Lead confirmado na LeadLovers antes da atualização.', [
                'lead_id' => $this->leadId,
                'sync_version' => $this->syncVersion,
            ]);

            if ($this->attempts() > 1) {
                $this->restartAfterReadinessConfirmation();

                return false;
            }

            return true;
        }

        $summary = $this->preservePreviousPatchDiagnostic([
            'success' => false,
            'status' => $providerStatus,
            'http_status' => $httpStatus,
            'requested_fields' => $requestedFields,
            'response_message' => 'O lead ainda não foi confirmado para atualização na LeadLovers.',
            'readiness_check' => [
                'confirmed' => false,
                'provider_status' => $providerStatus,
                'provider_status_invalid' => $providerStatusInvalid,
                'remote_identity_candidates' => count($remoteIdentities),
                'partial_identity_records' => $partialIdentityRecords,
                'remote_email_matches' => $remoteEmailMatches,
                'explicit_failure' => $lookupExplicitlyFailed,
                'patch_attempt_started' => false,
            ],
        ], $lead);
        $updated = $this->updateCurrentVersion([
            'leadlovers_update_response' => $this->encodeSummary($summary),
        ], ['processing']);

        if ($updated === 0) {
            return false;
        }

        $statusCandidates = array_values(array_filter(
            [$httpStatus, ...$providerStatuses],
            static fn (mixed $status): bool => is_int($status)
        ));
        $lookupRetryable = $providerStatusInvalid
            || $statusCandidates === []
            || collect($statusCandidates)->contains(
                fn (int $status): bool => $status === 404
                    || $this->isRetryableStatus($status)
            );
        $ambiguousSuccessfulLookup = $lookupStatusesAreSuccessful
            && (
                count($remoteIdentities) > 1
                || (count($remoteIdentities) === 1
                    && (
                        ! $remoteEmailMatches
                        || $partialIdentityRecords > 0
                    ))
            );
        $terminalClientError = collect($statusCandidates)->contains(
            static fn (?int $status): bool => $status !== null
                && $status >= 400
                && $status < 500
                && ! in_array($status, [404, 408, 425, 429], true)
        );
        $permanentFailure = ! $lookupRetryable
            && (
                $lookupExplicitlyFailed
                || $ambiguousSuccessfulLookup
                || $terminalClientError
            );

        if ($permanentFailure) {
            $this->markFailed(
                'A consulta de prontidão do lead foi recusada pela LeadLovers.',
                $summary
            );

            Log::warning('Consulta de prontidão do lead recusada pela LeadLovers.', [
                'lead_id' => $this->leadId,
                'sync_version' => $this->syncVersion,
                'attempt' => $this->attempts(),
                'provider_status' => $providerStatus,
                'provider_status_invalid' => $providerStatusInvalid,
                'http_status' => $httpStatus,
                'remote_identity_candidates' => count($remoteIdentities),
                'partial_identity_records' => $partialIdentityRecords,
                'remote_email_matches' => $remoteEmailMatches,
                'explicit_failure' => $lookupExplicitlyFailed,
                'readiness_request_sent' => true,
                'patch_request_sent' => false,
                'retryable' => false,
            ]);

            return false;
        }

        Log::notice('Atualização adiada: lead ainda não confirmado remotamente.', [
            'lead_id' => $this->leadId,
            'sync_version' => $this->syncVersion,
            'attempt' => $this->attempts(),
            'provider_status' => $providerStatus,
            'provider_status_invalid' => $providerStatusInvalid,
            'http_status' => $httpStatus,
            'remote_identity_candidates' => count($remoteIdentities),
            'partial_identity_records' => $partialIdentityRecords,
            'remote_email_matches' => $remoteEmailMatches,
            'explicit_failure' => $lookupExplicitlyFailed,
            'readiness_request_sent' => true,
            'patch_request_sent' => false,
            'retryable' => true,
        ]);

        throw new RuntimeException(
            'O lead ainda não está disponível para atualização na LeadLovers.'
        );
    }

    private function restartAfterReadinessConfirmation(): bool
    {
        $atomicDatabaseHandoff = $this->canAtomicallyQueueDatabaseHandoff();
        $handoff = DB::transaction(function () use (
            $atomicDatabaseHandoff
        ): ?array {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->lockForUpdate()
                ->first();

            if (
                ! $lead
                || (int) $lead->leadlovers_update_version
                    !== $this->syncVersion
                || $lead->leadlovers_update_status !== 'processing'
                || $this->normalizedLeadCode(
                    $lead->leadlovers_lead_code
                ) === null
                || data_get(
                    $lead->leadlovers_update_response,
                    'readiness_check.confirmed'
                ) !== true
                || data_get(
                    $lead->leadlovers_update_response,
                    'readiness_check.patch_attempt_started'
                ) === true
            ) {
                return null;
            }

            $nextVersion = $this->syncVersion + 1;
            $requestedFields = $this->normalizeRequestedFields([
                ...$this->requestedFields,
                ...$this->requestedFieldsFromLead($lead),
            ]);

            if ($requestedFields === []) {
                return null;
            }

            $summary = $lead->leadlovers_update_response;

            if (! is_array($summary)) {
                return null;
            }

            $summary['readiness_check']['handoff_from_version'] =
                $this->syncVersion;
            $summary['readiness_check']['handoff_version'] = $nextVersion;
            $summary['readiness_check']['handoff_dispatched'] =
                $atomicDatabaseHandoff;

            $updated = Lead::query()
                ->whereKey($this->leadId)
                ->where('leadlovers_update_version', $this->syncVersion)
                ->where('leadlovers_update_status', 'processing')
                ->update([
                    'leadlovers_update_status' => 'pending',
                    'leadlovers_update_version' => $nextVersion,
                    'leadlovers_update_error' => null,
                    'leadlovers_update_response' => $this->encodeSummary(
                        $summary
                    ),
                ]);

            if ($updated !== 1) {
                return null;
            }

            $handoff = [
                'sync_version' => $nextVersion,
                'requested_fields' => $requestedFields,
            ];

            if ($atomicDatabaseHandoff) {
                $this->pushReadinessHandoffToDatabase($handoff);
            }

            return $handoff;
        });

        if ($handoff === null) {
            return false;
        }

        if (! $atomicDatabaseHandoff) {
            $this->dispatchReadinessHandoff($handoff);
        }

        Log::info('Atualização reiniciada após confirmação remota do lead.', [
            'lead_id' => $this->leadId,
            'previous_sync_version' => $this->syncVersion,
            'sync_version' => $handoff['sync_version'],
        ]);

        return true;
    }

    private function recoverPendingReadinessHandoff(): bool
    {
        $handoff = DB::transaction(function (): ?array {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->lockForUpdate()
                ->first();
            $summary = $lead?->leadlovers_update_response;

            if (
                ! $lead
                || $lead->leadlovers_update_status !== 'pending'
                || ! is_array($summary)
                || data_get(
                    $summary,
                    'readiness_check.handoff_from_version'
                ) !== $this->syncVersion
                || data_get(
                    $summary,
                    'readiness_check.handoff_version'
                ) !== (int) $lead->leadlovers_update_version
                || data_get(
                    $summary,
                    'readiness_check.handoff_dispatched'
                ) === true
                || $this->normalizedLeadCode(
                    $lead->leadlovers_lead_code
                ) === null
            ) {
                return null;
            }

            $requestedFields = $this->normalizeRequestedFields(
                is_array($summary['requested_fields'] ?? null)
                    ? $summary['requested_fields']
                    : []
            );

            if ($requestedFields === []) {
                return null;
            }

            return [
                'sync_version' => (int) $lead->leadlovers_update_version,
                'requested_fields' => $requestedFields,
            ];
        });

        if ($handoff === null) {
            return false;
        }

        $this->dispatchReadinessHandoff($handoff);

        Log::info('Handoff pendente da atualização confirmada foi recuperado.', [
            'lead_id' => $this->leadId,
            'previous_sync_version' => $this->syncVersion,
            'sync_version' => $handoff['sync_version'],
        ]);

        return true;
    }

    private function dispatchReadinessHandoff(array $handoff): void
    {
        $job = $this->readinessHandoffJob($handoff);
        Bus::dispatch($job);

        $this->markReadinessHandoffDispatched(
            $handoff['sync_version']
        );
    }

    private function pushReadinessHandoffToDatabase(array $handoff): void
    {
        $job = $this->readinessHandoffJob($handoff);
        $job->beforeCommit();

        Queue::connection('database')->push(
            $job,
            '',
            'leadlovers'
        );
    }

    private function readinessHandoffJob(array $handoff): self
    {
        $job = new self(
            leadId: $this->leadId,
            originalEmail: $this->originalEmail,
            syncVersion: $handoff['sync_version'],
            requestedFields: $handoff['requested_fields'],
        );
        $job->onQueue('leadlovers')->afterCommit();

        return $job;
    }

    private function canAtomicallyQueueDatabaseHandoff(): bool
    {
        if (config('queue.default') !== 'database') {
            return false;
        }

        $queueDatabaseConnection = config(
            'queue.connections.database.connection'
        );

        return blank($queueDatabaseConnection)
            || $queueDatabaseConnection === config('database.default');
    }

    private function markReadinessHandoffDispatched(int $syncVersion): void
    {
        DB::transaction(function () use ($syncVersion): void {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->lockForUpdate()
                ->first();
            $summary = $lead?->leadlovers_update_response;

            if (
                ! $lead
                || (int) $lead->leadlovers_update_version !== $syncVersion
                || $lead->leadlovers_update_status !== 'pending'
                || ! is_array($summary)
                || data_get(
                    $summary,
                    'readiness_check.handoff_from_version'
                ) !== $this->syncVersion
                || data_get(
                    $summary,
                    'readiness_check.handoff_version'
                ) !== $syncVersion
            ) {
                return;
            }

            $summary['readiness_check']['handoff_dispatched'] = true;
            Lead::query()
                ->whereKey($this->leadId)
                ->where('leadlovers_update_version', $syncVersion)
                ->where('leadlovers_update_status', 'pending')
                ->update([
                    'leadlovers_update_response' => $this->encodeSummary(
                        $summary
                    ),
                ]);
        });
    }

    private function markPendingReadinessHandoffFailed(): bool
    {
        return DB::transaction(function (): bool {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->lockForUpdate()
                ->first();
            $summary = $lead?->leadlovers_update_response;

            if (
                ! $lead
                || $lead->leadlovers_update_status !== 'pending'
                || ! is_array($summary)
                || data_get(
                    $summary,
                    'readiness_check.handoff_from_version'
                ) !== $this->syncVersion
                || data_get(
                    $summary,
                    'readiness_check.handoff_version'
                ) !== (int) $lead->leadlovers_update_version
            ) {
                return false;
            }

            return Lead::query()
                ->whereKey($this->leadId)
                ->where(
                    'leadlovers_update_version',
                    $lead->leadlovers_update_version
                )
                ->where('leadlovers_update_status', 'pending')
                ->update([
                    'leadlovers_update_status' => 'failed',
                    'leadlovers_update_error' => 'A atualização confirmada não pôde ser recolocada na fila.',
                ]) === 1;
        });
    }

    private function disablePendingReadinessHandoff(): bool
    {
        return DB::transaction(function (): bool {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->lockForUpdate()
                ->first();
            $summary = $lead?->leadlovers_update_response;

            if (
                ! $lead
                || $lead->leadlovers_update_status !== 'pending'
                || ! is_array($summary)
                || data_get(
                    $summary,
                    'readiness_check.handoff_from_version'
                ) !== $this->syncVersion
                || data_get(
                    $summary,
                    'readiness_check.handoff_version'
                ) !== (int) $lead->leadlovers_update_version
            ) {
                return false;
            }

            return Lead::query()
                ->whereKey($this->leadId)
                ->where(
                    'leadlovers_update_version',
                    $lead->leadlovers_update_version
                )
                ->where('leadlovers_update_status', 'pending')
                ->update([
                    'leadlovers_update_status' => 'disabled',
                    'leadlovers_update_error' => 'Integração com a LeadLovers desativada.',
                ]) === 1;
        });
    }

    private function shouldRestartAfterReadinessConfirmation(Lead $lead): bool
    {
        return $this->attempts() > 1
            && data_get(
                $lead->leadlovers_update_response,
                'readiness_check.confirmed'
            ) === true
            && data_get(
                $lead->leadlovers_update_response,
                'readiness_check.patch_attempt_started'
            ) !== true;
    }

    private function markPatchAttemptStarted(Lead $lead): bool
    {
        $summary = $lead->leadlovers_update_response;

        if (
            ! is_array($summary)
            || data_get($summary, 'readiness_check.confirmed') !== true
            || data_get(
                $summary,
                'readiness_check.patch_attempt_started'
            ) === true
        ) {
            return true;
        }

        $summary['readiness_check']['patch_attempt_started'] = true;
        $updated = $this->updateCurrentVersion([
            'leadlovers_update_response' => $this->encodeSummary($summary),
        ], ['processing']);

        if ($updated === 0) {
            return false;
        }

        $lead->forceFill([
            'leadlovers_update_response' => $summary,
        ]);

        return true;
    }

    private function remoteLeadIdentityData(array $response): array
    {
        $identities = [];
        $partialRecords = 0;

        foreach ($this->remoteLeadRecords($response) as $record) {
            $hasCode = array_key_exists('Code', $record)
                || array_key_exists('code', $record);
            $hasEmail = array_key_exists('Email', $record)
                || array_key_exists('email', $record);
            $code = $this->normalizedLeadCode(
                $record['Code'] ?? $record['code'] ?? null
            );
            $email = $this->normalizedRemoteEmail(
                $record['Email'] ?? $record['email'] ?? null
            );

            if ($code !== null && $email !== null) {
                $identities[$code."\0".$email] = [
                    'code' => $code,
                    'email' => $email,
                ];
            } elseif ($hasCode || $hasEmail) {
                $partialRecords++;
            }
        }

        return [
            'identities' => array_values($identities),
            'partial_records' => $partialRecords,
        ];
    }

    private function remoteLeadRecords(array $response): array
    {
        $records = [$response];

        foreach ($response as $key => $value) {
            if (is_int($key) && is_array($value)) {
                $records[] = $value;
            }
        }

        foreach (['Data', 'Result', 'Lead', 'Items'] as $key) {
            $candidate = $response[$key] ?? null;

            if (! is_array($candidate)) {
                continue;
            }

            $records = array_merge(
                $records,
                array_is_list($candidate) ? $candidate : [$candidate]
            );
        }

        return array_values(array_filter(
            $records,
            static fn (mixed $record): bool => is_array($record)
        ));
    }

    private function normalizedLeadCode(mixed $candidate): ?string
    {
        if (is_string($candidate)) {
            $candidate = trim($candidate);
        }

        if (
            ! is_int($candidate)
            && (! is_string($candidate) || ! ctype_digit($candidate))
        ) {
            return null;
        }

        $leadCode = filter_var($candidate, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 2147483647,
            ],
        ]);

        return $leadCode === false ? null : (string) $leadCode;
    }

    private function normalizedRemoteEmail(mixed $candidate): ?string
    {
        if (! is_string($candidate)) {
            return null;
        }

        $email = mb_strtolower(trim($candidate));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email
            : null;
    }

    private function lookupBodyExplicitlyFailed(array $response): bool
    {
        foreach ($this->remoteLeadRecords($response) as $record) {
            foreach (['Error', 'error', 'Exception', 'exception'] as $key) {
                if (array_key_exists($key, $record) && filled($record[$key])) {
                    return true;
                }
            }

            foreach (['Success', 'success'] as $key) {
                if (! array_key_exists($key, $record)) {
                    continue;
                }

                $value = is_string($record[$key])
                    ? mb_strtolower(trim($record[$key]))
                    : $record[$key];

                if (! in_array(
                    $value,
                    [true, 1, '1', 'true', 'yes', 'sim'],
                    true
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    private function remoteLeadStatusData(array $response): array
    {
        $statuses = [];
        $invalid = ($response['_provider_status_invalid'] ?? false) === true;

        foreach ($response['_provider_statuses'] ?? [] as $candidate) {
            $status = $this->normalizedStatusCode($candidate);

            if ($status === null) {
                $invalid = true;
            } else {
                $statuses[$status] = true;
            }
        }

        foreach ($this->remoteLeadRecords($response) as $record) {
            foreach (['StatusCode', 'statusCode'] as $key) {
                if (! array_key_exists($key, $record)) {
                    continue;
                }

                $status = $this->normalizedStatusCode($record[$key]);

                if ($status === null) {
                    $invalid = true;
                } else {
                    $statuses[$status] = true;
                }
            }
        }

        return [
            'statuses' => array_map('intval', array_keys($statuses)),
            'invalid' => $invalid,
        ];
    }

    private function normalizedStatusCode(mixed $status): ?int
    {
        if (is_string($status)) {
            $status = trim($status);
        }

        if (
            ! is_int($status)
            && (! is_string($status) || ! ctype_digit($status))
        ) {
            return null;
        }

        $normalized = filter_var($status, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 100,
                'max_range' => 599,
            ],
        ]);

        return $normalized === false ? null : $normalized;
    }

    private function preservePreviousPatchDiagnostic(
        array $summary,
        Lead $lead
    ): array {
        $storedResponse = $lead->leadlovers_update_response;
        $storedDiagnostic = is_array($storedResponse)
            ? $this->providerDiagnosticSummary(
                $storedResponse['provider_diagnostic']
                    ?? $storedResponse['previous_patch_diagnostic']
                    ?? null
            )
            : null;

        if ($storedDiagnostic !== null) {
            $storedDiagnostic['preserved_from_previous_attempt'] = true;
            $summary['previous_patch_diagnostic'] = $storedDiagnostic;
        }

        return $summary;
    }

    private function providerDiagnosticSummary(mixed $diagnostic): ?array
    {
        if (! is_array($diagnostic)) {
            return null;
        }

        $fingerprint = $diagnostic['fingerprint'] ?? null;
        $messageBytes = $diagnostic['message_bytes'] ?? null;
        $capturedBytes = $diagnostic['captured_bytes'] ?? null;
        $truncated = $diagnostic['truncated'] ?? null;

        if (
            ($diagnostic['version'] ?? null) !== 1
            || ($diagnostic['classification'] ?? null)
                !== 'unclassified_provider_error'
            || ! is_string($fingerprint)
            || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1
            || ! is_int($messageBytes)
            || $messageBytes < 1
            || ! is_int($capturedBytes)
            || $capturedBytes < 1
            || $capturedBytes > 2048
            || $capturedBytes > $messageBytes
            || ! is_bool($truncated)
            || $truncated !== ($capturedBytes < $messageBytes)
        ) {
            return null;
        }

        $summary = [
            'version' => 1,
            'classification' => 'unclassified_provider_error',
            'fingerprint' => $fingerprint,
            'message_bytes' => $messageBytes,
            'captured_bytes' => $capturedBytes,
            'truncated' => $truncated,
        ];
        $ciphertext = $diagnostic['ciphertext'] ?? null;

        if (
            is_string($ciphertext)
            && $ciphertext !== ''
            && strlen($ciphertext) <= 16384
        ) {
            $summary['ciphertext'] = $ciphertext;
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
