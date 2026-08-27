<?php

namespace App\Jobs;

use App\Exceptions\LeadLoversApiException;
use App\Exceptions\PermanentLeadTagException;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Models\LeadLoversTagOperation;
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversResultTagService;
use App\Services\LeadLoversTagOperationCoordinator;
use App\Services\RejectedLeadRetentionService;
use App\Events\DashboardActivityChanged;
use App\Support\ManualLeadResultTags;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplyFinalAnalysisTagToLeadLoversJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const PHASE_CONFIRMATION = 'confirmation';

    private const ATTEMPT_START_EVENTS = [
        'created',
        'analysis_restarted',
        'analysis_started',
        'reanalysis_requested',
        'reanalysis_started',
        'technical_retry_requested',
    ];

    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 10800;

    public ?string $phase = null;

    /** @var array{actionId: int, status: string, total: int}|null */
    public ?array $bulkAction = null;

    public ?int $version = null;

    private ?int $trackedInflightVersion = null;

    public function __construct(
        public int $batchId,
        public ?string $attemptId = null,
        public bool $isReanalysis = false,
        ?string $phase = null,
        ?array $bulkAction = null,
        ?int $version = null,
    ) {
        $this->phase = $phase;
        $this->bulkAction = $bulkAction;
        $this->version = $version;
    }

    public function uniqueId(): string
    {
        $attempt = filled($this->attemptId)
            ? (string) $this->attemptId
            : 'legacy';
        $phase = $this->isConfirmationPhase() ? ':confirmation' : '';

        return 'leadlovers-final-analysis-tag:'
            .$this->batchId.':'.$attempt
            .($this->version !== null ? ':version:'.$this->version : '')
            .$phase;
    }

    public function overlapKey(): string
    {
        $leadId = InsuranceAnalysisBatch::query()
            ->whereKey($this->batchId)
            ->value('lead_id');

        return $leadId !== null
            ? 'leadlovers-result-tag:lead:'.$leadId
            : 'leadlovers-result-tag:batch:'.$this->batchId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->overlapKey()))
                ->shared()
                ->releaseAfter(15)
                ->expireAfter(180),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(3);
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120, 180];
    }

    public function handle(
        LeadLoversApiClient $leadLovers,
        LeadLoversResultTagService $resultTags,
        LeadLoversTagOperationCoordinator $coordinator,
        RejectedLeadRetentionService $retention
    ): void {
        if (
            ! config('features.insurance_analysis.enabled', false)
            || ! config('services.leadlovers.enabled', false)
        ) {
            Log::notice(
                'Job de análise ignorado porque o módulo está desativado.',
                ['job' => static::class]
            );

            return;
        }

        try {
            $this->process($leadLovers, $resultTags, $coordinator, $retention);
        } catch (PermanentLeadTagException $exception) {
            $this->blockInflightAfterTerminalFailure(
                $coordinator,
                'local_failure'
            );
            $this->recordFailure($exception);
            $this->fail($exception);
        } catch (LeadLoversApiException $exception) {
            if (! $exception->isTransient) {
                $this->blockInflightAfterTerminalFailure(
                    $coordinator,
                    'api_failure'
                );
                $this->recordFailure($exception);
                $this->fail($exception);

                return;
            }

            if ($this->attempts() >= $this->tries) {
                $this->blockInflightAfterTerminalFailure(
                    $coordinator,
                    'transient_retry_exhausted'
                );
                $this->recordFailure($exception);
                $this->fail($exception);

                return;
            }

            $delay = $this->retryDelay($exception);

            Log::notice(
                'Aplicação da tag final devolvida à fila por falha transitória.',
                [
                    'batch_id' => $this->batchId,
                    'phase' => $this->currentPhase(),
                    'attempt' => $this->attempts(),
                    'status' => $exception->statusCode,
                    'error_code' => $exception->errorCode,
                    'retry_after' => $delay,
                ]
            );

            $this->release($delay);
        } catch (Throwable $exception) {
            Log::warning(
                'Erro ao aplicar a tag final da análise na LeadLovers.',
                [
                    'batch_id' => $this->batchId,
                    'phase' => $this->currentPhase(),
                    'exception' => $exception::class,
                ]
            );

            throw $exception;
        }
    }

    private function process(
        LeadLoversApiClient $leadLovers,
        LeadLoversResultTagService $resultTags,
        LeadLoversTagOperationCoordinator $coordinator,
        RejectedLeadRetentionService $retention
    ): void {
        $batch = InsuranceAnalysisBatch::with([
            'lead',
            'analyses.events',
        ])->findOrFail($this->batchId);
        $lead = $batch->lead;
        $attemptIsCurrent = $this->attemptIsCurrent($batch);
        $state = $lead instanceof Lead
            ? $coordinator->snapshot($lead->id)
            : null;
        $this->trackedInflightVersion = $this->ownsAnalysisInflight($state, $batch)
            ? $state->inflight_version
            : null;

        if (
            ! $attemptIsCurrent
            && ! $this->ownsAnalysisInflight($state, $batch)
        ) {
            return;
        }

        if (! $lead instanceof Lead) {
            throw new PermanentLeadTagException(
                'O lote não possui um lead associado.'
            );
        }

        if ((int) $lead->leadlovers_lead_id <= 0) {
            throw new PermanentLeadTagException(
                'O lead não possui um ID remoto válido da LeadLovers.'
            );
        }

        $tagKey = $attemptIsCurrent
            ? $this->resolveFinalTagKey($batch)
            : null;

        if ($attemptIsCurrent && $tagKey === null) {
            $this->registerEventForAllAnalyses(
                batch: $batch,
                eventType: 'leadlovers_final_tag_not_resolved',
                status: null,
                message: 'Não foi possível resolver uma tag final para o lote.',
                payload: []
            );

            return;
        }

        if ($attemptIsCurrent && is_string($tagKey)) {
            $state = $coordinator->registerAnalysisDesired(
                leadId: $lead->id,
                tagKey: $tagKey,
                batchId: $batch->id,
                attemptId: $this->attemptId,
                isReanalysis: $this->isReanalysis,
            );

            if ($this->stateDesiresThisAnalysis($state, $batch)) {
                $lead->forceFill([
                    'analysis_final_status' => match ($tagKey) {
                        'aprovados' => 'approved',
                        'ruim' => 'rejected',
                        'em_negociacao' => 'negotiation',
                        default => null,
                    },
                    'analysis_final_tag_key' => $tagKey,
                    'last_analysis_batch_id' => $batch->id,
                    'analysis_finalized_at' => now(),
                ])->save();
            } elseif (! $this->ownsAnalysisInflight($state, $batch)) {
                return;
            }
        }

        if (
            $state instanceof LeadLoversTagOperation
            && ! $this->stateDesiresThisAnalysis($state, $batch)
            && ! $this->ownsAnalysisInflight($state, $batch)
        ) {
            return;
        }

        if (
            ! $state instanceof LeadLoversTagOperation
            || $state->phase === LeadLoversTagOperationCoordinator::PHASE_BLOCKED
        ) {
            return;
        }

        if (
            $this->version !== null
            && $state->inflight_version !== $this->version
        ) {
            return;
        }

        if (
            $state->inflight_version === null
            && (
                ! $attemptIsCurrent
                || ! is_string($tagKey)
                || ! $this->stateDesiresThisAnalysis($state, $batch)
            )
        ) {
            return;
        }

        if (
            $this->isConfirmationPhase()
            && $state->inflight_version === null
        ) {
            if ($this->version !== null) {
                return;
            }

            $pendingOperation = is_string($tagKey)
                ? $this->pendingOperation($batch, $tagKey)
                : null;
            $pendingAction = is_array($pendingOperation)
                ? $this->normalizedBulkAction(
                    $pendingOperation['response'] ?? null
                )
                : $this->bulkActionOrNull();

            if ($pendingAction === null && ! is_array($pendingOperation)) {
                return;
            }
            $state = $coordinator->adoptExistingInflight(
                leadId: $lead->id,
                version: $state->version,
                action: $pendingAction,
                outcomeUncertain: $pendingAction === null,
            ) ?? $state;
        }

        $inflightVersion = $state->inflight_version;
        $operationTagKey = $inflightVersion !== null
            ? $state->inflight_tag_key
            : $tagKey;

        if (! is_string($operationTagKey)) {
            return;
        }

        $this->trackedInflightVersion = $inflightVersion;

        $catalog = $resultTags->catalog();
        $selectedTag = $resultTags->selectedTag($catalog, $operationTagKey);
        $remoteLeadId = (int) $lead->leadlovers_lead_id;
        $remoteTags = $leadLovers->listLeadTags($remoteLeadId);
        $plan = $resultTags->plan(
            remoteTags: $remoteTags,
            catalog: $catalog,
            selectedTag: $selectedTag,
            remoteLeadId: $remoteLeadId,
        );

        $freshState = $coordinator->snapshot($lead->id);

        if (! $freshState instanceof LeadLoversTagOperation) {
            return;
        }

        if ($inflightVersion !== null) {
            if (
                $freshState->inflight_version !== $inflightVersion
                || $freshState->inflight_tag_key !== $operationTagKey
            ) {
                return;
            }
        } elseif (
            $freshState->version !== $state->version
            || $freshState->inflight_version !== null
            || ! $this->attemptIsCurrent($batch)
            || ! $this->stateDesiresThisAnalysis($freshState, $batch)
        ) {
            return;
        }

        $state = $freshState;
        $attemptIsCurrent = $this->attemptIsCurrent($batch);

        if ($plan['confirmed']) {
            if (
                $inflightVersion !== null
                && (
                    $state->version !== $inflightVersion
                    || ! $attemptIsCurrent
                    || ! $this->stateDesiresThisAnalysis($state, $batch)
                )
            ) {
                $drained = $coordinator->completeAndDrain(
                    $lead->id,
                    $inflightVersion
                );

                if ($drained instanceof LeadLoversTagOperation) {
                    $this->dispatchDesiredState($drained);
                }

                return;
            }

            if (! $attemptIsCurrent || ! is_string($tagKey)) {
                return;
            }

            $coordinator->completeCurrent(
                $lead->id,
                $state->version,
                fn (LeadLoversTagOperation $lockedState): bool =>
                    $this->persistConfirmedTag(
                        batch: $batch,
                        resultTags: $resultTags,
                        catalog: $catalog,
                        selectedTag: $selectedTag,
                        tagKey: $operationTagKey,
                        retention: $retention,
                        remoteTags: $remoteTags,
                        operationVersion: $lockedState->version,
                    )
            );

            return;
        }

        if ($inflightVersion !== null) {
            if ($state->phase === LeadLoversTagOperationCoordinator::PHASE_POSTING) {
                $state = $coordinator->markPostingAsUncertain(
                    $lead->id,
                    $inflightVersion
                ) ?? $state;
            }

            $state = $coordinator->incrementConfirmation(
                $lead->id,
                $inflightVersion
            ) ?? $state;

            if (! $this->isConfirmationPhase()) {
                $this->dispatchConfirmation();

                return;
            }

            if (
                $state->outcome_uncertain
                && $state->version === $inflightVersion
                && $attemptIsCurrent
                && $this->uncertainPostCanBeRetried($state)
            ) {
                $claimed = $coordinator->reclaimUncertainPost(
                    $lead->id,
                    $inflightVersion
                );

                if ($claimed instanceof LeadLoversTagOperation) {
                    $this->postMutation(
                        $leadLovers,
                        $coordinator,
                        $batch,
                        $operationTagKey,
                        $plan,
                        $inflightVersion,
                    );
                }

                return;
            }

            $this->releaseUnconfirmedState(
                $batch,
                $operationTagKey,
                $plan,
                $state,
                $coordinator,
                $inflightVersion,
            );

            return;
        }

        $pendingOperation = $this->pendingOperation($batch, $operationTagKey);

        if (is_array($pendingOperation)) {
            $this->bulkAction = $this->normalizedBulkAction(
                $pendingOperation['response'] ?? null
            );
            $this->dispatchConfirmation();

            return;
        }

        $claimed = $coordinator->claimBeforePost($lead->id, $state->version);

        if ($claimed === null || $claimed->inflight_version === null) {
            $this->dispatchConfirmation();

            return;
        }

        $this->trackedInflightVersion = $claimed->inflight_version;

        $this->postMutation(
            $leadLovers,
            $coordinator,
            $batch,
            $operationTagKey,
            $plan,
            $claimed->inflight_version,
        );
    }

    /**
     * @param  Collection<string, LeadLoversTag>  $catalog
     */
    private function persistConfirmedTag(
        InsuranceAnalysisBatch $batch,
        LeadLoversResultTagService $resultTags,
        Collection $catalog,
        LeadLoversTag $selectedTag,
        RejectedLeadRetentionService $retention,
        array $remoteTags,
        int $operationVersion,
        string $tagKey
    ): bool {
        return DB::transaction(function () use (
            $batch,
            $resultTags,
            $catalog,
            $selectedTag,
            $tagKey,
            $retention,
            $remoteTags,
            $operationVersion,
        ): bool {
            $lead = Lead::query()
                ->lockForUpdate()
                ->findOrFail((int) $batch->lead_id);

            if (! $this->attemptIsCurrent($batch)) {
                return false;
            }

            $alreadyApplied = $this->finalTagAlreadyApplied($batch, $tagKey);

            $lead->forceFill([
                'tags_originais' => $resultTags->replaceLocalFinalTag(
                    currentTagString: $lead->tags_originais,
                    catalog: $catalog,
                    selectedTag: $selectedTag,
                ),
            ]);

            $retention->applyConfirmedFinalTag(
                lead: $lead,
                tagKey: $tagKey,
                remoteTagId: (int) $selectedTag->leadlovers_tag_id,
                remoteTags: $remoteTags,
                operationVersion: $operationVersion,
            );

            $tagsChanged = $lead->wasChanged('tags_originais');

            if (! $alreadyApplied) {
                $this->registerEventForAllAnalyses(
                    batch: $batch,
                    eventType: 'leadlovers_final_tag_applied',
                    status: $tagKey,
                    message: 'Tag final confirmada na LeadLovers.',
                    payload: [
                        'tag_id' => (int) $selectedTag->leadlovers_tag_id,
                        'tag_key' => $tagKey,
                        'phase' => 'confirmed',
                    ],
                    response: $this->bulkActionOrNull()
                );
            }

            if ($tagsChanged) {

                $resourceId = (int) $lead->id;

                $companyId = $lead->company_id !== null ? (int) $lead->company_id : null;


                DashboardActivityChanged::dispatch(
                    'lead',
                    $resourceId,
                    $companyId,
                    'lead.analysis-result.changed',
                );
            }

            return true;
        });
    }

    private function ownsAnalysisInflight(
        ?LeadLoversTagOperation $state,
        InsuranceAnalysisBatch $batch
    ): bool {
        return $state instanceof LeadLoversTagOperation
            && $state->inflight_source === 'analysis'
            && $state->inflight_batch_id === $batch->id
            && $state->inflight_attempt_id === $this->attemptId;
    }

    private function stateDesiresThisAnalysis(
        LeadLoversTagOperation $state,
        InsuranceAnalysisBatch $batch
    ): bool {
        return $state->desired_source === 'analysis'
            && $state->desired_batch_id === $batch->id
            && $state->desired_attempt_id === $this->attemptId;
    }

    /**
     * @param  array{payload: array{applyTags: array<int, int>, removeTags: array<int, int>, leadsIds: array<int, int>}}  $plan
     */
    private function postMutation(
        LeadLoversApiClient $leadLovers,
        LeadLoversTagOperationCoordinator $coordinator,
        InsuranceAnalysisBatch $batch,
        string $tagKey,
        array $plan,
        int $inflightVersion
    ): void {
        try {
            $bulkAction = $leadLovers->mutateLeadTags($plan['payload']);
        } catch (LeadLoversApiException $exception) {
            if ($this->mutationOutcomeMayBeUncertain($exception)) {
                $coordinator->markUncertain($batch->lead_id, $inflightVersion);
                $this->recordPendingOperation($batch, $tagKey, null, true);
                $this->dispatchConfirmation();

                return;
            }

            $coordinator->markDefiniteRejection($batch->lead_id, $inflightVersion);

            throw $exception;
        }

        $this->bulkAction = $bulkAction;
        $coordinator->markAccepted($batch->lead_id, $inflightVersion, $bulkAction);
        $this->recordPendingOperation($batch, $tagKey, $bulkAction, false);
        $this->dispatchConfirmation();
    }

    private function dispatchDesiredState(LeadLoversTagOperation $state): void
    {
        if ($state->phase !== LeadLoversTagOperationCoordinator::PHASE_PENDING) {
            return;
        }

        if (
            $state->desired_source === 'manual'
            && is_string($state->desired_result)
            && $state->desired_corretor_id !== null
            && $state->desired_request_log_id !== null
        ) {
            ApplyManualLeadResultTagJob::dispatch(
                leadId: (int) $state->lead_id,
                result: $state->desired_result,
                corretorId: $state->desired_corretor_id,
                requestLogId: $state->desired_request_log_id,
                version: $state->version,
            )->afterCommit();

            return;
        }

        if (
            $state->desired_source === 'analysis'
            && $state->desired_batch_id !== null
            && is_string($state->desired_attempt_id)
        ) {
            self::dispatch(
                batchId: $state->desired_batch_id,
                attemptId: $state->desired_attempt_id,
                isReanalysis: (bool) $state->desired_is_reanalysis,
                version: $state->version,
            )->afterCommit();
        }
    }

    private function blockInflightAfterTerminalFailure(
        LeadLoversTagOperationCoordinator $coordinator,
        string $reason
    ): void {
        $leadId = InsuranceAnalysisBatch::query()
            ->whereKey($this->batchId)
            ->value('lead_id');

        if ($leadId === null) {
            return;
        }

        $state = $coordinator->snapshot((int) $leadId);
        $ownsInflight = $state instanceof LeadLoversTagOperation
            && $state->inflight_source === 'analysis'
            && $state->inflight_batch_id === $this->batchId
            && $state->inflight_attempt_id === $this->attemptId;
        $desiresThisAnalysis = $state instanceof LeadLoversTagOperation
            && $state->desired_source === 'analysis'
            && $state->desired_batch_id === $this->batchId
            && $state->desired_attempt_id === $this->attemptId;

        if (
            $this->trackedInflightVersion === null
            ||
            ! $state instanceof LeadLoversTagOperation
            || $state->inflight_version !== $this->trackedInflightVersion
            || $state->inflight_source !== 'analysis'
            || (! $ownsInflight && ! $desiresThisAnalysis)
            || in_array(
                $state->phase,
                [
                    LeadLoversTagOperationCoordinator::PHASE_FAILED,
                    LeadLoversTagOperationCoordinator::PHASE_BLOCKED,
                ],
                true
            )
        ) {
            return;
        }

        $coordinator->block(
            (int) $leadId,
            $state->inflight_version,
            $reason
        );
    }

    private function uncertainPostCanBeRetried(
        LeadLoversTagOperation $state
    ): bool {
        return $state->confirmation_checks >= $this->uncertainRetryChecks()
            && $state->post_attempts < $this->maxPostAttempts()
            && $state->last_posted_at !== null
            && $state->last_posted_at->addSeconds($this->postingStaleSeconds())->isPast();
    }

    /**
     * @param  array{selectedPresent: bool, otherFinalTagIds: array<int, int>, remoteTagIds: array<int, int>}  $plan
     */
    private function releaseUnconfirmedState(
        InsuranceAnalysisBatch $batch,
        string $tagKey,
        array $plan,
        LeadLoversTagOperation $state,
        LeadLoversTagOperationCoordinator $coordinator,
        int $inflightVersion
    ): void {
        Log::notice(
            'Tag final da análise ainda não foi confirmada remotamente.',
            [
                'batch_id' => $batch->id,
                'lead_id' => $batch->lead_id,
                'tag_key' => $tagKey,
                'attempt' => $this->attempts(),
                'selected_tag_found' => $plan['selectedPresent'],
                'remaining_tag_ids' => $plan['otherFinalTagIds'],
                'confirmed_remote_tag_ids' => $plan['remoteTagIds'],
            ]
        );

        if ($state->confirmation_checks >= $this->confirmationBudget()) {
            $coordinator->block(
                $batch->lead_id,
                $inflightVersion,
                'confirmation_budget_exhausted'
            );
            $exception = new PermanentLeadTagException(
                'A tag final não foi confirmada dentro da janela esperada.'
            );
            $this->recordFailure($exception, $batch, $tagKey);
            $this->fail($exception);

            return;
        }

        $this->release($this->confirmationDelay());
    }

    /**
     * @param  array{actionId: int, status: string, total: int}|null  $bulkAction
     */
    private function recordPendingOperation(
        InsuranceAnalysisBatch $batch,
        string $tagKey,
        ?array $bulkAction,
        bool $outcomeUncertain
    ): void {
        $this->registerEventForAllAnalyses(
            batch: $batch,
            eventType: 'leadlovers_final_tag_pending_confirmation',
            status: $tagKey,
            message: 'A alteração remota aguarda confirmação.',
            payload: [
                'tag_key' => $tagKey,
                'phase' => 'pending_confirmation',
                'outcome_uncertain' => $outcomeUncertain,
            ],
            response: $bulkAction
        );
    }

    private function dispatchConfirmation(): void
    {
        $version = $this->trackedInflightVersion;

        if ($version === null) {
            $leadId = InsuranceAnalysisBatch::query()
                ->whereKey($this->batchId)
                ->value('lead_id');
            $version = $leadId !== null
                ? app(LeadLoversTagOperationCoordinator::class)
                    ->snapshot((int) $leadId)?->inflight_version
                : null;
        }

        self::dispatch(
            batchId: $this->batchId,
            attemptId: $this->attemptId,
            isReanalysis: $this->isReanalysis,
            phase: self::PHASE_CONFIRMATION,
            bulkAction: $this->bulkActionOrNull(),
            version: $version,
        )
            ->delay(now()->addSeconds($this->confirmationDelay()))
            ->afterCommit();
    }

    private function resolveFinalTagKey(
        InsuranceAnalysisBatch $batch
    ): ?string {
        $statuses = $batch->analyses
            ->pluck('status')
            ->filter()
            ->map(fn (mixed $status): string => mb_strtolower((string) $status))
            ->values();

        if ($statuses->isEmpty()) {
            return null;
        }

        if ($statuses->contains(fn (string $status): bool => in_array(
            $status,
            ['approved', 'quoted'],
            true
        ))) {
            return ManualLeadResultTags::leadloversKey(
                ManualLeadResultTags::APPROVED
            );
        }

        if ($statuses->contains(fn (string $status): bool => in_array(
            $status,
            [
                'pending',
                'processing',
                'queued',
                'running',
                'manual_review',
                'underanalysis',
                'failed',
                'error',
            ],
            true
        ))) {
            return ManualLeadResultTags::leadloversKey(
                ManualLeadResultTags::IN_NEGOTIATION
            );
        }

        $allRejected = $statuses->every(fn (string $status): bool => in_array(
            $status,
            ['rejected', 'denied', 'refused'],
            true
        ));

        return $allRejected
            ? ManualLeadResultTags::leadloversKey(
                ManualLeadResultTags::REJECTED
            )
            : null;
    }

    private function attemptIsCurrent(InsuranceAnalysisBatch $batch): bool
    {
        if (blank($this->attemptId)) {
            return false;
        }

        $batchStatus = InsuranceAnalysisBatch::query()
            ->whereKey($batch->id)
            ->value('status');

        if (! in_array($batchStatus, ['completed', 'completed_with_errors'], true)) {
            return false;
        }

        $analysisIds = $batch->analyses()->select('id');
        $tickets = InsuranceAnalysisEvent::query()
            ->whereIn('insurance_analysis_id', clone $analysisIds)
            ->where('event_type', 'email_queued')
            ->orderBy('id')
            ->get(['id', 'payload']);
        $ticket = $tickets->last(
            fn (InsuranceAnalysisEvent $event): bool => data_get($event->payload, 'attempt_id') === $this->attemptId
        );

        if (
            ! $ticket instanceof InsuranceAnalysisEvent
            || $tickets->last()?->id !== $ticket->id
        ) {
            return false;
        }

        $markers = InsuranceAnalysisEvent::query()
            ->whereIn(
                'insurance_analysis_id',
                clone $analysisIds
            )
            ->whereIn('event_type', self::ATTEMPT_START_EVENTS)
            ->orderBy('id')
            ->get(['id', 'insurance_analysis_id', 'payload']);

        if ($markers->contains(function (InsuranceAnalysisEvent $event) use ($ticket): bool {
            $attemptId = data_get($event->payload, 'attempt_id');

            return $event->id > $ticket->id
                && is_string($attemptId)
                && $attemptId !== $this->attemptId;
        })) {
            return false;
        }
        $ownedAnalysisIds = $markers
            ->filter(fn (InsuranceAnalysisEvent $event): bool => data_get($event->payload, 'attempt_id') === $this->attemptId
            )
            ->pluck('insurance_analysis_id')
            ->unique();

        if ($ownedAnalysisIds->isEmpty()) {
            return false;
        }

        return $ownedAnalysisIds->every(function (int $analysisId) use ($markers): bool {
            $latest = $markers
                ->where('insurance_analysis_id', $analysisId)
                ->sortByDesc('id')
                ->first();

            return $latest instanceof InsuranceAnalysisEvent
                && data_get($latest->payload, 'attempt_id') === $this->attemptId;
        });
    }

    private function finalTagAlreadyApplied(
        InsuranceAnalysisBatch $batch,
        string $tagKey
    ): bool {
        return $batch->analyses->contains(
            fn (InsuranceAnalysis $analysis): bool => $this->analysisHasEvent(
                $analysis,
                'leadlovers_final_tag_applied',
                $tagKey
            )
        );
    }

    /** @return array{response: mixed}|null */
    private function pendingOperation(
        InsuranceAnalysisBatch $batch,
        string $tagKey
    ): ?array {
        foreach ($batch->analyses as $analysis) {
            $event = $this->matchingEvent(
                $analysis,
                'leadlovers_final_tag_pending_confirmation',
                $tagKey
            );

            if ($event !== null) {
                return ['response' => $event->response];
            }
        }

        return null;
    }

    private function analysisHasEvent(
        InsuranceAnalysis $analysis,
        string $eventType,
        ?string $tagKey
    ): bool {
        return $this->matchingEvent($analysis, $eventType, $tagKey) !== null;
    }

    private function matchingEvent(
        InsuranceAnalysis $analysis,
        string $eventType,
        ?string $tagKey
    ): ?Model {
        return $analysis->events()
            ->where('event_type', $eventType)
            ->get(['payload', 'response'])
            ->first(function ($event) use ($tagKey): bool {
                return data_get($event->payload, 'attempt_id') === $this->attemptId
                    && data_get($event->payload, 'tag_key') === $tagKey;
            });
    }

    private function registerEventForAllAnalyses(
        InsuranceAnalysisBatch $batch,
        string $eventType,
        ?string $status,
        string $message,
        array $payload = [],
        ?array $response = null
    ): void {
        $deduplicateByTagKey = array_key_exists('tag_key', $payload);
        $tagKey = is_string($payload['tag_key'] ?? null)
            ? $payload['tag_key']
            : null;

        foreach ($batch->analyses as $analysis) {
            if (
                $deduplicateByTagKey
                && $this->analysisHasEvent($analysis, $eventType, $tagKey)
            ) {
                continue;
            }

            $analysis->events()->create([
                'event_type' => $eventType,
                'status' => $status ?? $analysis->status,
                'message' => $message,
                'payload' => array_merge($payload, [
                    'attempt_id' => $this->attemptId,
                    'is_reanalysis' => $this->isReanalysis,
                    'batch_id' => $batch->id,
                ]),
                'response' => $response,
            ]);
        }
    }

    private function recordFailure(
        Throwable $exception,
        ?InsuranceAnalysisBatch $batch = null,
        ?string $tagKey = null
    ): void {
        try {
            $batch ??= InsuranceAnalysisBatch::with('analyses.events')
                ->find($this->batchId);

            if (! $batch instanceof InsuranceAnalysisBatch) {
                return;
            }

            $this->registerEventForAllAnalyses(
                batch: $batch,
                eventType: 'leadlovers_final_tag_failed',
                status: $tagKey,
                message: 'Não foi possível confirmar a tag final na LeadLovers.',
                payload: [
                    'tag_key' => $tagKey,
                    'phase' => $this->currentPhase(),
                    'exception' => $exception::class,
                    'http_status' => $exception instanceof LeadLoversApiException
                        ? $exception->statusCode
                        : null,
                    'error_code' => $exception instanceof LeadLoversApiException
                        ? $exception->errorCode
                        : null,
                ],
                response: $this->bulkActionOrNull()
            );
        } catch (Throwable $logException) {
            Log::critical(
                'Falha ao registrar o erro da tag final da análise.',
                [
                    'batch_id' => $this->batchId,
                    'exception' => $logException::class,
                ]
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->blockInflightAfterTerminalFailure(
            app(LeadLoversTagOperationCoordinator::class),
            'job_failed'
        );
        $this->recordFailure(
            $exception ?? new PermanentLeadTagException(
                'O job de tag final falhou sem uma exceção disponível.'
            )
        );
    }

    private function mutationOutcomeMayBeUncertain(
        LeadLoversApiException $exception
    ): bool {
        return $exception->isTransient
            && $exception->errorCode !== 'LOCAL_RATE_LIMIT'
            && $exception->statusCode !== 429;
    }

    private function retryDelay(LeadLoversApiException $exception): int
    {
        if ($exception->retryAfterSeconds !== null) {
            return max(1, $exception->retryAfterSeconds);
        }

        $backoff = $this->backoff();
        $index = min(max(0, $this->attempts() - 1), count($backoff) - 1);

        return $backoff[$index];
    }

    private function confirmationDelay(): int
    {
        return max(
            1,
            (int) config(
                'services.leadlovers.tag_confirmation_delay_seconds',
                15
            )
        );
    }

    private function uncertainRetryChecks(): int
    {
        return max(
            1,
            (int) config('services.leadlovers.tag_uncertain_retry_checks', 2)
        );
    }

    private function maxPostAttempts(): int
    {
        return max(
            1,
            (int) config('services.leadlovers.tag_max_post_attempts', 2)
        );
    }

    private function postingStaleSeconds(): int
    {
        return max(
            1,
            (int) config('services.leadlovers.tag_posting_stale_seconds', 60)
        );
    }

    private function confirmationBudget(): int
    {
        return max(1, $this->tries);
    }

    private function currentPhase(): string
    {
        return $this->isConfirmationPhase()
            ? self::PHASE_CONFIRMATION
            : 'mutation';
    }

    private function isConfirmationPhase(): bool
    {
        return isset($this->phase)
            && $this->phase === self::PHASE_CONFIRMATION;
    }

    /** @return array{actionId: int, status: string, total: int}|null */
    private function bulkActionOrNull(): ?array
    {
        return $this->normalizedBulkAction(
            isset($this->bulkAction) ? $this->bulkAction : null
        );
    }

    /** @return array{actionId: int, status: string, total: int}|null */
    private function normalizedBulkAction(mixed $candidate): ?array
    {
        $statuses = [
            'pending',
            'mapping',
            'processing',
            'done',
            'failed',
            'cancelled',
        ];

        if (
            ! is_array($candidate)
            || count($candidate) !== 3
            || ! array_key_exists('actionId', $candidate)
            || ! array_key_exists('status', $candidate)
            || ! array_key_exists('total', $candidate)
            || ! is_int($candidate['actionId'])
            || $candidate['actionId'] <= 0
            || ! is_string($candidate['status'])
            || ! in_array($candidate['status'], $statuses, true)
            || ! is_int($candidate['total'])
            || $candidate['total'] < 0
        ) {
            return null;
        }

        return [
            'actionId' => $candidate['actionId'],
            'status' => $candidate['status'],
            'total' => $candidate['total'],
        ];
    }
}
