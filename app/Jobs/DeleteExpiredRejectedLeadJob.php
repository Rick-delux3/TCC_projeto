<?php

namespace App\Jobs;

use App\Events\DashboardActivityChanged;
use App\Exceptions\LeadLoversApiException;
use App\Exceptions\PermanentLeadTagException;
use App\Models\Lead;
use App\Models\LeadLoversTagOperation;
use App\Models\LeadRetentionEvent;
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversResultTagService;
use App\Services\LeadLoversTagOperationCoordinator;
use App\Services\RejectedLeadRetentionService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteExpiredRejectedLeadJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 21600;

    public function __construct(
        public readonly int $leadId,
        public readonly string $expectedDeletionDueAt,
        public readonly ?int $expectedConfirmedTagVersion,
    ) {
        $this->onQueue('leadlovers');
    }

    public function uniqueId(): string
    {
        return implode(':', [
            'delete-expired-rejected-lead',
            $this->leadId,
            $this->expectedConfirmedTagVersion ?? 'none',
            hash('sha256', $this->expectedDeletionDueAt),
        ]);
    }

    public function overlapKey(): string
    {
        return 'leadlovers-result-tag:lead:'.$this->leadId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->overlapKey()))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter(120),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function backoff(): array
    {
        return [30, 60, 120, 300, 600];
    }

    public function handle(
        LeadLoversApiClient $leadLovers,
        LeadLoversResultTagService $resultTags,
        RejectedLeadRetentionService $retention,
    ): void {
        if (! config('services.leadlovers.enabled', false)) {
            Log::warning(
                'Exclusao de lead recusado preservada porque a integracao esta desativada.',
                ['lead_id' => $this->leadId]
            );

            return;
        }

        $lead = Lead::query()->find($this->leadId);

        if (! $lead instanceof Lead) {
            return;
        }

        try {
            $operation = $this->operation();

            if (! $this->snapshotIsValid($lead, $operation, $retention)) {
                return;
            }

            $catalog = $resultTags->catalog();
            $rejectedTag = $resultTags->selectedTag(
                $catalog,
                $retention->rejectedTagKey()
            );
            $remoteTags = $leadLovers->listLeadTags(
                (int) $lead->leadlovers_lead_id
            );
            $plan = $resultTags->plan(
                remoteTags: $remoteTags,
                catalog: $catalog,
                selectedTag: $rejectedTag,
                remoteLeadId: (int) $lead->leadlovers_lead_id,
            );

            if ($plan['confirmed']) {
                $this->deleteIfStillValid(
                    retention: $retention,
                    verification: 'exclusive_rejected_tag',
                    remoteStatus: 200,
                );

                return;
            }

            $this->cancelIfStillValid(
                retention: $retention,
                reason: $plan['selectedPresent']
                    ? 'conflicting_final_tag'
                    : 'rejected_tag_absent',
                conflictingFinalTagCount: count($plan['otherFinalTagIds']),
            );
        } catch (LeadLoversApiException $exception) {
            if ($exception->statusCode === 404) {
                $this->deleteIfStillValid(
                    retention: $retention,
                    verification: 'remote_lead_not_found',
                    remoteStatus: 404,
                );

                return;
            }

            if ($exception->statusCode === 429) {
                $delay = max(
                    1,
                    $exception->retryAfterSeconds
                        ?? $this->backoff()[0]
                );

                Log::notice(
                    'Verificacao de exclusao devolvida a fila por limite remoto.',
                    [
                        'lead_id' => $this->leadId,
                        'status' => 429,
                        'retry_after' => $delay,
                    ]
                );

                $this->release($delay);

                return;
            }

            if (
                $exception->errorCode === 'INVALID_RESPONSE'
                || $exception->isConfigurationError
                || ! $exception->isTransient
            ) {
                $this->recordPreserved(
                    $lead,
                    reason: $exception->isConfigurationError
                        ? 'configuration_failure'
                        : ($exception->errorCode === 'INVALID_RESPONSE'
                            ? 'invalid_response'
                            : 'permanent_remote_failure'),
                    status: $exception->statusCode,
                    errorCode: $exception->errorCode,
                );

                return;
            }

            Log::notice(
                'Verificacao de exclusao falhou de forma transitoria.',
                [
                    'lead_id' => $this->leadId,
                    'status' => $exception->statusCode,
                    'error_code' => $exception->errorCode,
                ]
            );

            throw $exception;
        } catch (PermanentLeadTagException $exception) {
            $this->recordPreserved(
                $lead,
                reason: 'local_tag_configuration_failure',
                errorCode: $exception::class,
            );
        } catch (Throwable $exception) {
            Log::warning(
                'Falha inesperada ao verificar exclusao de lead recusado.',
                [
                    'lead_id' => $this->leadId,
                    'exception' => $exception::class,
                ]
            );

            throw $exception;
        }
    }

    private function operation(bool $lockForUpdate = false): ?LeadLoversTagOperation
    {
        $query = LeadLoversTagOperation::query()
            ->where('lead_id', $this->leadId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function snapshotIsValid(
        Lead $lead,
        ?LeadLoversTagOperation $operation,
        RejectedLeadRetentionService $retention,
    ): bool {
        $expectedDueAt = $this->expectedDueAt();
        $actualDueAt = $lead->rejected_deletion_due_at?->toImmutable()->utc();

        if (
            $expectedDueAt === null
            || $lead->leadlovers_confirmed_final_tag_key !== $retention->rejectedTagKey()
            || $actualDueAt === null
            || $actualDueAt->isFuture()
            || ! $actualDueAt->equalTo($expectedDueAt)
            || $lead->leadlovers_confirmed_tag_version !== $this->expectedConfirmedTagVersion
            || (int) $lead->leadlovers_lead_id <= 0
            || $lead->sent_to_leadlovers_at === null
        ) {
            return false;
        }

        if (! $operation instanceof LeadLoversTagOperation) {
            return $this->expectedConfirmedTagVersion === null;
        }

        return $this->expectedConfirmedTagVersion !== null
            && $operation->version === $this->expectedConfirmedTagVersion
            && $operation->inflight_version === null
            && ! in_array($operation->phase, [
                LeadLoversTagOperationCoordinator::PHASE_PENDING,
                LeadLoversTagOperationCoordinator::PHASE_POSTING,
                LeadLoversTagOperationCoordinator::PHASE_CONFIRMING,
            ], true);
    }

    private function expectedDueAt(): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->expectedDeletionDueAt)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function deleteIfStillValid(
        RejectedLeadRetentionService $retention,
        string $verification,
        int $remoteStatus,
    ): void {
        DB::transaction(function () use (
            $retention,
            $verification,
            $remoteStatus,
        ): void {
            $lead = Lead::query()
                ->lockForUpdate()
                ->find($this->leadId);

            if (! $lead instanceof Lead) {
                return;
            }

            $operation = $this->operation(lockForUpdate: true);

            if (! $this->snapshotIsValid($lead, $operation, $retention)) {
                return;
            }

            $resourceId = (int) $lead->id;
            $companyId = $lead->company_id !== null
                ? (int) $lead->company_id
                : null;

            $this->recordEvent(
                lead: $lead,
                event: LeadRetentionEvent::EVENT_DELETED,
                context: [
                    'source' => 'expired_rejected_lead_job',
                    'verification' => $verification,
                    'remote_status' => $remoteStatus,
                ],
            );

            $lead->delete();

            DashboardActivityChanged::dispatch(
                'lead',
                $resourceId,
                $companyId,
                'lead.deleted',
            );
        });
    }

    private function cancelIfStillValid(
        RejectedLeadRetentionService $retention,
        string $reason,
        int $conflictingFinalTagCount,
    ): void {
        DB::transaction(function () use (
            $retention,
            $reason,
            $conflictingFinalTagCount,
        ): void {
            $lead = Lead::query()
                ->lockForUpdate()
                ->find($this->leadId);

            if (! $lead instanceof Lead) {
                return;
            }

            $operation = $this->operation(lockForUpdate: true);

            if (! $this->snapshotIsValid($lead, $operation, $retention)) {
                return;
            }

            $dueAt = $lead->rejected_deletion_due_at;

            $lead->forceFill([
                'rejected_deletion_due_at' => null,
            ])->save();

            $this->recordEvent(
                lead: $lead,
                event: LeadRetentionEvent::EVENT_CANCELLED,
                context: [
                    'source' => 'expired_rejected_lead_job',
                    'verification' => $reason,
                    'remote_status' => 200,
                    'conflicting_final_tag_count' => $conflictingFinalTagCount,
                ],
                deletionDueAt: $dueAt,
            );
        });
    }

    private function recordPreserved(
        Lead $lead,
        string $reason,
        ?int $status = null,
        ?string $errorCode = null,
    ): void {
        if (! Lead::query()->whereKey($lead->id)->exists()) {
            return;
        }

        $this->recordEvent(
            lead: $lead,
            event: LeadRetentionEvent::EVENT_DEFERRED,
            context: array_filter([
                'source' => 'expired_rejected_lead_job',
                'verification' => $reason,
                'remote_status' => $status,
                'error_code' => $errorCode,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private function recordEvent(
        Lead $lead,
        string $event,
        array $context,
        mixed $deletionDueAt = null,
    ): void {
        LeadRetentionEvent::query()->create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'leadlovers_lead_id' => $lead->leadlovers_lead_id,
            'event' => $event,
            'confirmed_tag_key' => $lead->leadlovers_confirmed_final_tag_key,
            'operation_version' => $lead->leadlovers_confirmed_tag_version,
            'confirmed_at' => $lead->leadlovers_final_tag_confirmed_at,
            'deletion_due_at' => $deletionDueAt
                ?? $lead->rejected_deletion_due_at,
            'context' => $context,
        ]);
    }
}
