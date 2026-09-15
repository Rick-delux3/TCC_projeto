<?php

namespace App\Jobs;

use App\Events\DashboardActivityChanged;
use App\Exceptions\LeadLoversApiException;
use App\Exceptions\PermanentLeadTagException;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Models\LeadLoversTagOperation;
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversResultTagService;
use App\Services\LeadLoversTagOperationCoordinator;
use App\Services\RejectedLeadRetentionService;
use App\Support\ManualLeadResultTags;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApplyManualLeadResultTagJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    private const PHASE_CONFIRMATION = 'confirmation';

    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 10800;

    public ?int $requestLogId = null;

    /** @var array{actionId: int, status: string, total: int}|null */
    public ?array $bulkAction = null;

    public ?string $phase = null;

    public ?int $version = null;

    private ?int $trackedInflightVersion = null;

    public function __construct(
        public int $leadId,
        public string $result,
        public int $corretorId,
        public ?string $ip = null,
        public ?string $userAgent = null,
        ?int $requestLogId = null,
        ?string $phase = null,
        ?array $bulkAction = null,
        ?int $version = null,
    ) {
        $this->requestLogId = $requestLogId;
        $this->phase = $phase;
        $this->bulkAction = $bulkAction;
        $this->version = $version;
    }

    public function uniqueId(): string
    {
        $requestLogId = isset($this->requestLogId)
            ? $this->requestLogId
            : null;
        $requestVersion = $requestLogId !== null
            ? 'request:'.$requestLogId
            : 'legacy:'.$this->result;
        $phase = $this->isConfirmationPhase() ? ':confirmation' : '';

        $version = $this->versionOrNull();

        return 'manual-lead-result-tag:'
            .$this->leadId.':'.($version !== null ? 'version:'.$version : $requestVersion).$phase;
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
                ->releaseAfter(15)
                ->expireAfter(360),
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
        RejectedLeadRetentionService $retetion
    ): void {
        try {
            $this->process($leadLovers, $resultTags, $coordinator, $retetion);
        } catch (PermanentLeadTagException $exception) {
            $coordinator->failUnstartedManualDesired(
                leadId: $this->leadId,
                version: $this->versionOrNull(),
                requestLogId: $this->requestLogIdOrNull(),
                corretorId: $this->corretorId,
            );
            $this->blockInflightAfterTerminalFailure(
                $coordinator,
                'local_failure'
            );
            $this->logPermanentFailure($exception);
            $this->fail($exception);
        } catch (LeadLoversApiException $exception) {
            if (! $exception->isTransient) {
                $this->blockInflightAfterTerminalFailure(
                    $coordinator,
                    'api_failure'
                );
                $this->logApiFailure($exception);
                $this->fail($exception);

                return;
            }

            if ($this->attempts() >= $this->tries) {
                $this->blockInflightAfterTerminalFailure(
                    $coordinator,
                    'transient_retry_exhausted'
                );
                $this->fail($exception);

                return;
            }

            $delay = $this->retryDelay($exception);

            Log::notice(
                'Alteração manual de tag devolvida à fila por falha transitória.',
                [
                    'lead_id' => $this->leadId,
                    'corretor_id' => $this->corretorId,
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
                'Tentativa de alteração manual da tag do lead falhou.',
                [
                    'lead_id' => $this->leadId,
                    'corretor_id' => $this->corretorId,
                    'result' => $this->result,
                    'phase' => $this->currentPhase(),
                    'attempt' => $this->attempts(),
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
        RejectedLeadRetentionService $retetion
    ): void {
        $initialState = $coordinator->snapshot($this->leadId);
        $this->trackedInflightVersion = $initialState?->inflight_version;

        if (
            $this->requestLogIdOrNull() !== null
            && ! $this->requestLogExists()
        ) {
            $coordinator->failUnstartedManualDesired(
                leadId: $this->leadId,
                version: $this->versionOrNull(),
                requestLogId: $this->requestLogIdOrNull(),
                corretorId: $this->corretorId,
            );

            throw new PermanentLeadTagException(
                'A solicitação original da alteração não está mais disponível.'
            );
        }

        [$lead, $catalog, $selectedTag] = $this->context($resultTags);

        $state = $this->resolveState($coordinator, $selectedTag);

        if ($state === null || $state->phase === LeadLoversTagOperationCoordinator::PHASE_BLOCKED) {
            return;
        }

        $inflightVersion = $state->inflight_version;
        $inflightTagKey = $state->inflight_tag_key;
        $this->trackedInflightVersion = $inflightVersion;

        if ($inflightVersion !== null && is_string($inflightTagKey)) {
            $selectedTag = $resultTags->selectedTag($catalog, $inflightTagKey);
        }

        if ($inflightVersion === null && $this->requestWasSuperseded()) {
            $this->logSupersededRequest();

            return;
        }

        if ($inflightVersion === null && $this->requestWasCompleted()) {
            return;
        }

        $remoteLeadId = (int) $lead->leadlovers_lead_id;
        $remoteTags = $leadLovers->listLeadTags($remoteLeadId);
        $plan = $resultTags->plan(
            remoteTags: $remoteTags,
            catalog: $catalog,
            selectedTag: $selectedTag,
            remoteLeadId: $remoteLeadId,
        );

        if ($inflightVersion === null && $this->requestWasSuperseded()) {
            $this->logSupersededRequest();

            return;
        }

        if ($plan['confirmed']) {
            if ($inflightVersion !== null && $state->version !== $inflightVersion) {
                $drained = $coordinator->completeAndDrain($this->leadId, $inflightVersion);

                if ($drained !== null && $drained->version !== $inflightVersion) {
                    $this->dispatchDesiredState($drained);
                }

                return;
            }

            $completed = $coordinator->completeCurrent(
                $this->leadId,
                $state->version,
                fn (LeadLoversTagOperation $lockedState): mixed => $this->persistConfirmedTags(
                    resultTags: $resultTags,
                    catalog: $catalog,
                    selectedTag: $selectedTag,
                    retetion: $retetion,
                    remoteTags: $remoteTags,
                    operationVersion: $lockedState->version,
                )
            );

            if ($completed === null) {
                return;
            }

            return;
        }

        if ($inflightVersion !== null) {
            if (! $this->isConfirmationPhase()) {
                $this->bulkAction = $this->stateBulkAction($state);
                $this->dispatchConfirmation();

                return;
            }

            $state = $this->recoverOrCountConfirmation(
                $coordinator,
                $state,
                $inflightVersion
            );

            if (
                $state->outcome_uncertain
                && $state->version === $inflightVersion
                && $this->uncertainPostCanBeRetried($state)
            ) {
                $claimed = $coordinator->reclaimUncertainPost(
                    $this->leadId,
                    $inflightVersion
                );

                if ($claimed !== null) {
                    $this->postMutation(
                        $leadLovers,
                        $coordinator,
                        $resultTags,
                        $catalog,
                        $selectedTag,
                        $plan,
                        $claimed->inflight_version,
                    );
                }

                return;
            }

            if (
                $state->outcome_uncertain
                && $state->version !== $inflightVersion
                && $state->confirmation_checks >= $this->confirmationBudget()
            ) {
                $coordinator->block(
                    $this->leadId,
                    $inflightVersion,
                    'uncertain_predecessor'
                );

                return;
            }

            $this->releaseUnconfirmedState($plan, $state, $coordinator);

            return;
        }

        $claimed = $coordinator->claimBeforePost($this->leadId, $state->version);

        if ($claimed === null || $claimed->inflight_version === null) {
            $this->release($this->confirmationDelay());

            return;
        }

        $this->trackedInflightVersion = $claimed->inflight_version;

        $this->postMutation(
            $leadLovers,
            $coordinator,
            $resultTags,
            $catalog,
            $selectedTag,
            $plan,
            $claimed->inflight_version,
        );
    }

    /**
     * @return array{0: Lead, 1: Collection<string, LeadLoversTag>, 2: LeadLoversTag}
     */
    private function context(
        LeadLoversResultTagService $resultTags
    ): array {
        $lead = Lead::query()->find($this->leadId);

        if (! $lead instanceof Lead) {
            throw new PermanentLeadTagException(
                'O lead solicitado não existe mais.'
            );
        }

        $corretor = Corretor::query()->find($this->corretorId);

        if (! $corretor instanceof Corretor) {
            throw new PermanentLeadTagException(
                'O corretor solicitante não existe mais.'
            );
        }

        if (! Gate::forUser($corretor)->allows('manage-lead-tags')) {
            throw new PermanentLeadTagException(
                'O corretor não possui permissão para gerenciar tags.'
            );
        }

        if (! config('services.leadlovers.enabled', false)) {
            throw new PermanentLeadTagException(
                'A integração com a LeadLovers está desativada.'
            );
        }

        if (
            $lead->leadlovers_status !== 'sent'
            || $lead->sent_to_leadlovers_at === null
        ) {
            throw new PermanentLeadTagException(
                'O lead ainda não foi enviado para a LeadLovers.'
            );
        }

        if ((int) $lead->leadlovers_lead_id <= 0) {
            throw new PermanentLeadTagException(
                'O lead não possui um ID remoto válido da LeadLovers.'
            );
        }

        if (! in_array($this->result, ManualLeadResultTags::keys(), true)) {
            throw new PermanentLeadTagException(
                'O resultado solicitado não é permitido.'
            );
        }

        $selectedTagKey = ManualLeadResultTags::leadloversKey($this->result);

        if ($selectedTagKey === null) {
            throw new PermanentLeadTagException(
                'Não foi possível mapear o resultado para uma tag.'
            );
        }

        $catalog = $resultTags->catalog();
        $selectedTag = $resultTags->selectedTag($catalog, $selectedTagKey);

        return [$lead, $catalog, $selectedTag];
    }

    private function resolveState(
        LeadLoversTagOperationCoordinator $coordinator,
        LeadLoversTag $selectedTag
    ): ?LeadLoversTagOperation {
        $state = $coordinator->snapshot($this->leadId);
        $version = $this->versionOrNull();

        if ($state === null && $this->requestLogIdOrNull() !== null) {
            $state = $coordinator->registerManualDesired(
                leadId: $this->leadId,
                tagKey: (string) $selectedTag->key,
                result: $this->result,
                requestLogId: $this->requestLogIdOrNull(),
                corretorId: $this->corretorId,
            );
            $this->version = $state->version;

            $pending = $this->pendingOperation();

            if ($pending instanceof CorretorActivityLog || $this->isConfirmationPhase()) {
                $action = $pending instanceof CorretorActivityLog
                    ? $this->normalizedBulkAction(
                        data_get($pending->new_values, 'bulk_action')
                    )
                    : null;

                $state = $coordinator->adoptExistingInflight(
                    leadId: $this->leadId,
                    version: $state->version,
                    action: $action,
                    outcomeUncertain: $action === null,
                ) ?? $state;
            }
        }

        if ($state === null || ($version !== null && $version > $state->version)) {
            return null;
        }

        if (
            $version !== null
            && $version < $state->version
            && $state->inflight_version === null
        ) {
            return null;
        }

        if (
            ! $this->stateDesiresThisManualRequest($state, $selectedTag)
            && ! $this->ownsManualInflight($state, $selectedTag)
        ) {
            return null;
        }

        return $state;
    }

    private function stateDesiresThisManualRequest(
        LeadLoversTagOperation $state,
        LeadLoversTag $selectedTag
    ): bool {
        return $state->desired_source === 'manual'
            && $state->desired_request_log_id === $this->requestLogIdOrNull()
            && $state->desired_corretor_id === $this->corretorId
            && $state->desired_result === $this->result
            && $state->desired_tag_key === $selectedTag->key;
    }

    private function ownsManualInflight(
        LeadLoversTagOperation $state,
        LeadLoversTag $selectedTag
    ): bool {
        return $state->inflight_source === 'manual'
            && $state->inflight_request_log_id === $this->requestLogIdOrNull()
            && $state->inflight_corretor_id === $this->corretorId
            && $state->inflight_result === $this->result
            && $state->inflight_tag_key === $selectedTag->key;
    }

    /**
     * @param  array{payload: array{applyTags: array<int, int>, removeTags: array<int, int>, leadsIds: array<int, int>}}  $plan
     */
    private function postMutation(
        LeadLoversApiClient $leadLovers,
        LeadLoversTagOperationCoordinator $coordinator,
        LeadLoversResultTagService $resultTags,
        Collection $catalog,
        LeadLoversTag $selectedTag,
        array $plan,
        int $inflightVersion
    ): void {
        try {
            $bulkAction = $leadLovers->mutateLeadTags($plan['payload']);
        } catch (LeadLoversApiException $exception) {
            if ($this->mutationOutcomeMayBeUncertain($exception)) {
                $coordinator->markUncertain($this->leadId, $inflightVersion);
                $this->recordPendingOperation(null, true);
                $this->release($this->confirmationDelay());

                return;
            }

            $coordinator->markDefiniteRejection($this->leadId, $inflightVersion);

            throw $exception;
        }

        $this->bulkAction = $bulkAction;
        $coordinator->markAccepted($this->leadId, $inflightVersion, $bulkAction);
        $this->recordPendingOperation($bulkAction, false);

        if ($this->isConfirmationPhase()) {
            $this->release($this->confirmationDelay());
        } else {
            $this->dispatchConfirmation();
        }
    }

    private function recoverOrCountConfirmation(
        LeadLoversTagOperationCoordinator $coordinator,
        LeadLoversTagOperation $state,
        int $inflightVersion
    ): LeadLoversTagOperation {
        if ($state->phase === LeadLoversTagOperationCoordinator::PHASE_POSTING) {
            $state = $coordinator->markPostingAsUncertain(
                $this->leadId,
                $inflightVersion
            ) ?? $state;
        }

        return $coordinator->incrementConfirmation(
            $this->leadId,
            $inflightVersion
        ) ?? $state;
    }

    private function uncertainPostCanBeRetried(
        LeadLoversTagOperation $state
    ): bool {
        return $state->confirmation_checks >= $this->uncertainRetryChecks()
            && $state->post_attempts < $this->maxPostAttempts()
            && $state->last_posted_at !== null
            && $state->last_posted_at->addSeconds($this->postingStaleSeconds())->isPast();
    }

    private function dispatchDesiredState(LeadLoversTagOperation $state): void
    {
        if (
            $state->desired_source !== 'manual'
            || ! is_string($state->desired_result)
            || $state->desired_corretor_id === null
            || $state->desired_request_log_id === null
        ) {
            return;
        }

        self::dispatch(
            leadId: $this->leadId,
            result: $state->desired_result,
            corretorId: $state->desired_corretor_id,
            requestLogId: $state->desired_request_log_id,
            version: $state->version,
        )->afterCommit();
    }

    private function blockInflightAfterTerminalFailure(
        LeadLoversTagOperationCoordinator $coordinator,
        string $reason
    ): void {
        $state = $coordinator->snapshot($this->leadId);

        if (
            $this->trackedInflightVersion === null
            || ! $state instanceof LeadLoversTagOperation
            || $state->inflight_version !== $this->trackedInflightVersion
            || $state->inflight_source !== 'manual'
            || (
                $state->inflight_request_log_id !== $this->requestLogIdOrNull()
                && $state->desired_request_log_id !== $this->requestLogIdOrNull()
            )
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
            $this->leadId,
            $this->trackedInflightVersion,
            $reason
        );
    }

    /**
     * @param  array{selectedPresent: bool, otherFinalTagIds: array<int, int>, remoteTagIds: array<int, int>}  $plan
     */
    private function releaseUnconfirmedState(
        array $plan,
        LeadLoversTagOperation $state,
        LeadLoversTagOperationCoordinator $coordinator
    ): void {
        Log::notice(
            'Estado remoto das tags ainda não foi confirmado.',
            [
                'lead_id' => $this->leadId,
                'request_log_id' => $this->requestLogIdOrNull(),
                'attempt' => $this->attempts(),
                'selected_tag_found' => $plan['selectedPresent'],
                'remaining_tag_ids' => $plan['otherFinalTagIds'],
                'confirmed_remote_tag_ids' => $plan['remoteTagIds'],
            ]
        );

        if ($state->confirmation_checks >= $this->confirmationBudget()) {
            if ($state->inflight_version !== null) {
                $coordinator->block(
                    $this->leadId,
                    $state->inflight_version,
                    'confirmation_budget_exhausted'
                );
            }

            $this->fail(new PermanentLeadTagException(
                'O estado final das tags não foi confirmado dentro da janela esperada.'
            ));

            return;
        }

        $this->release($this->confirmationDelay());
    }

    /**
     * @param  array{actionId: int, status: string, total: int}|null  $bulkAction
     */
    private function recordPendingOperation(
        ?array $bulkAction,
        bool $outcomeUncertain
    ): void {
        if ($this->requestHasPendingOperation()) {
            return;
        }

        CorretorActivityLog::query()->create([
            'corretor_id' => $this->corretorId,
            'action' => 'lead_tag_update_pending_confirmation',
            'model_type' => Lead::class,
            'model_id' => $this->leadId,
            'new_values' => [
                'request_log_id' => $this->requestLogIdOrNull(),
                'requested_result' => $this->result,
                'leadlovers_tag_key' => ManualLeadResultTags::leadloversKey($this->result),
                'phase' => 'pending_confirmation',
                'outcome_uncertain' => $outcomeUncertain,
                'bulk_action' => $bulkAction,
            ],
            'description' => 'A alteração remota foi aceita ou enviada e aguarda confirmação.',
            'ip' => $this->normalizedIp(),
            'user_agent' => $this->normalizedUserAgent(),
        ]);
    }

    private function dispatchConfirmation(): void
    {
        self::dispatch(
            leadId: $this->leadId,
            result: $this->result,
            corretorId: $this->corretorId,
            ip: $this->ip,
            userAgent: $this->userAgent,
            requestLogId: $this->requestLogIdOrNull(),
            phase: self::PHASE_CONFIRMATION,
            bulkAction: $this->bulkActionOrNull(),
            version: $this->versionOrNull(),
        )
            ->delay(now()->addSeconds($this->confirmationDelay()))
            ->afterCommit();
    }

    /**
     * @param  Collection<string, LeadLoversTag>  $catalog
     */
    private function persistConfirmedTags(
        LeadLoversResultTagService $resultTags,
        Collection $catalog,
        LeadLoversTag $selectedTag,
        RejectedLeadRetentionService $retetion,
        array $remoteTags,
        int $operationVersion
    ): bool {
        $resultLabel = ManualLeadResultTags::label($this->result) ?? $this->result;
        $selectedTagKey = ManualLeadResultTags::leadloversKey($this->result);

        return DB::transaction(function () use (
            $resultTags,
            $catalog,
            $selectedTag,
            $resultLabel,
            $selectedTagKey,
            $retetion,
            $remoteTags,
            $operationVersion,
        ): bool {
            $lead = Lead::query()
                ->lockForUpdate()
                ->findOrFail($this->leadId);

            if ($this->requestWasSuperseded()) {
                $this->logSupersededRequest();

                return false;
            }

            if ($this->requestWasCompleted()) {
                return true;
            }

            $oldTags = $lead->tags_originais;
            $oldCorretorId = $lead->updated_by_corretor_id;
            $newTags = $resultTags->replaceLocalFinalTag(
                currentTagString: $oldTags,
                catalog: $catalog,
                selectedTag: $selectedTag,
            );

            $lead->forceFill([
                'tags_originais' => $newTags,
                'updated_by_corretor_id' => $this->corretorId,
            ]);

            $retetion->applyConfirmedFinalTag(
                lead: $lead,
                tagKey: (string) $selectedTag->key,
                remoteTagId: (int) $selectedTag->leadlovers_tag_id,
                remoteTags: $remoteTags,
                operationVersion: $operationVersion
            );

            CorretorActivityLog::query()->create([
                'corretor_id' => $this->corretorId,
                'action' => 'lead_tag_update_completed',
                'model_type' => Lead::class,
                'model_id' => $lead->id,
                'old_values' => [
                    'tags_originais' => $oldTags,
                    'updated_by_corretor_id' => $oldCorretorId,
                ],
                'new_values' => [
                    'request_log_id' => $this->requestLogIdOrNull(),
                    'tags_originais' => $newTags,
                    'updated_by_corretor_id' => $this->corretorId,
                    'result' => $this->result,
                    'result_label' => $resultLabel,
                    'leadlovers_tag_key' => $selectedTagKey,
                    'leadlovers_tag_id' => (int) $selectedTag->leadlovers_tag_id,
                    'bulk_action' => $this->bulkActionOrNull(),
                ],
                'description' => sprintf(
                    'Resultado comercial do lead alterado para "%s" após confirmação na LeadLovers.',
                    $resultLabel
                ),
                'ip' => $this->normalizedIp(),
                'user_agent' => $this->normalizedUserAgent(),
            ]);

            DashboardActivityChanged::dispatch(
                'lead',
                (int) $lead->id,
                $lead->company_id !== null ? (int) $lead->company_id : null,
                'lead.tags.changed',
            );

            return true;
        });
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

    private function requestWasCompleted(): bool
    {
        return $this->activityExistsForRequest('lead_tag_update_completed');
    }

    private function requestLogExists(): bool
    {
        $requestLogId = $this->requestLogIdOrNull();

        if ($requestLogId === null) {
            return true;
        }

        return CorretorActivityLog::query()
            ->whereKey($requestLogId)
            ->where('corretor_id', $this->corretorId)
            ->where('action', 'lead_tag_update_requested')
            ->where('model_type', Lead::class)
            ->where('model_id', $this->leadId)
            ->exists();
    }

    private function requestHasPendingOperation(): bool
    {
        return $this->pendingOperation() instanceof CorretorActivityLog;
    }

    private function pendingOperation(): ?CorretorActivityLog
    {
        $requestLogId = $this->requestLogIdOrNull();

        if ($requestLogId === null) {
            return null;
        }

        return CorretorActivityLog::query()
            ->where('action', 'lead_tag_update_pending_confirmation')
            ->where('model_type', Lead::class)
            ->where('model_id', $this->leadId)
            ->get(['new_values'])
            ->first(
                fn (CorretorActivityLog $log): bool => (int) data_get($log->new_values, 'request_log_id')
                        === $requestLogId
            );
    }

    private function activityExistsForRequest(string $action): bool
    {
        $requestLogId = $this->requestLogIdOrNull();

        if ($requestLogId === null) {
            return false;
        }

        return CorretorActivityLog::query()
            ->where('action', $action)
            ->where('model_type', Lead::class)
            ->where('model_id', $this->leadId)
            ->get(['new_values'])
            ->contains(
                fn (CorretorActivityLog $log): bool => (int) data_get($log->new_values, 'request_log_id')
                        === $requestLogId
            );
    }

    public function failed(?Throwable $exception): void
    {
        $this->blockInflightAfterTerminalFailure(
            app(LeadLoversTagOperationCoordinator::class),
            'job_failed'
        );

        try {
            $lead = Lead::query()->find($this->leadId);
            $corretorExists = Corretor::query()
                ->whereKey($this->corretorId)
                ->exists();

            if (! $lead instanceof Lead || ! $corretorExists) {
                Log::error(
                    'Não foi possível registrar a falha da alteração de tag.',
                    [
                        'lead_id' => $this->leadId,
                        'corretor_id' => $this->corretorId,
                    ]
                );

                return;
            }

            if (! $this->activityExistsForRequest('lead_tag_update_failed')) {
                CorretorActivityLog::query()->create([
                    'corretor_id' => $this->corretorId,
                    'action' => 'lead_tag_update_failed',
                    'model_type' => Lead::class,
                    'model_id' => $lead->id,
                    'old_values' => [
                        'tags_originais' => $lead->tags_originais,
                    ],
                    'new_values' => [
                        'request_log_id' => $this->requestLogIdOrNull(),
                        'requested_result' => $this->result,
                        'requested_label' => ManualLeadResultTags::label($this->result),
                        'leadlovers_tag_key' => ManualLeadResultTags::leadloversKey($this->result),
                        'phase' => $this->currentPhase(),
                        'error' => 'Falha ao concluir a alteração manual solicitada.',
                        'exception' => $exception !== null
                            ? $exception::class
                            : null,
                        'status' => $exception instanceof LeadLoversApiException
                            ? $exception->statusCode
                            : null,
                        'error_code' => $exception instanceof LeadLoversApiException
                            ? $exception->errorCode
                            : null,
                        'bulk_action' => $this->bulkActionOrNull(),
                    ],
                    'description' => 'Não foi possível concluir a alteração manual da tag do lead.',
                    'ip' => $this->normalizedIp(),
                    'user_agent' => $this->normalizedUserAgent(),
                ]);
            }

            if ($this->requestLogIdOrNull() !== null) {
                DashboardActivityChanged::dispatch(
                    'lead',
                    (int) $lead->id,
                    $lead->company_id !== null ? (int) $lead->company_id : null,
                    'lead.tags.processing.failed',
                );
            }

            Log::error(
                'Alteração manual da tag do lead falhou definitivamente.',
                [
                    'lead_id' => $this->leadId,
                    'corretor_id' => $this->corretorId,
                    'result' => $this->result,
                    'phase' => $this->currentPhase(),
                    'exception' => $exception !== null
                        ? $exception::class
                        : null,
                ]
            );
        } catch (Throwable $logException) {
            Log::critical(
                'Falha ao registrar auditoria do Job de tags.',
                [
                    'lead_id' => $this->leadId,
                    'corretor_id' => $this->corretorId,
                    'exception' => $logException::class,
                ]
            );
        }
    }

    private function requestWasSuperseded(): bool
    {
        $latestRequest = CorretorActivityLog::query()
            ->where('action', 'lead_tag_update_requested')
            ->where('model_type', Lead::class)
            ->where('model_id', $this->leadId)
            ->latest('id')
            ->first(['id', 'new_values']);
        $requestLogId = $this->requestLogIdOrNull();

        if (! $latestRequest instanceof CorretorActivityLog) {
            return $requestLogId !== null;
        }

        if ($requestLogId !== null) {
            return (int) $latestRequest->id !== $requestLogId;
        }

        $latestResult = data_get(
            $latestRequest->new_values,
            'requested_result'
        );

        return is_string($latestResult)
            && $latestResult !== $this->result;
    }

    private function logSupersededRequest(): void
    {
        Log::notice(
            'Alteração manual de tag ignorada por existir uma decisão mais recente.',
            [
                'lead_id' => $this->leadId,
                'corretor_id' => $this->corretorId,
                'request_log_id' => $this->requestLogIdOrNull(),
                'phase' => $this->currentPhase(),
            ]
        );
    }

    private function logPermanentFailure(
        PermanentLeadTagException $exception
    ): void {
        Log::warning(
            'Tentativa de alteração manual da tag do lead foi recusada localmente.',
            [
                'lead_id' => $this->leadId,
                'corretor_id' => $this->corretorId,
                'result' => $this->result,
                'phase' => $this->currentPhase(),
                'exception' => $exception::class,
            ]
        );
    }

    private function logApiFailure(LeadLoversApiException $exception): void
    {
        Log::error(
            'LeadLovers recusou permanentemente a alteração de tag.',
            [
                'lead_id' => $this->leadId,
                'corretor_id' => $this->corretorId,
                'phase' => $this->currentPhase(),
                'status' => $exception->statusCode,
                'error_code' => $exception->errorCode,
            ]
        );
    }

    private function requestLogIdOrNull(): ?int
    {
        return isset($this->requestLogId)
            ? $this->requestLogId
            : null;
    }

    private function versionOrNull(): ?int
    {
        return isset($this->version) && $this->version > 0
            ? $this->version
            : null;
    }

    /** @return array{actionId: int, status: string, total: int}|null */
    private function bulkActionOrNull(): ?array
    {
        return $this->normalizedBulkAction(
            isset($this->bulkAction) ? $this->bulkAction : null
        );
    }

    /** @return array{actionId: int, status: string, total: int}|null */
    private function stateBulkAction(LeadLoversTagOperation $state): ?array
    {
        return $this->normalizedBulkAction([
            'actionId' => $state->action_id,
            'status' => $state->action_status,
            'total' => $state->action_total,
        ]);
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

    private function normalizedIp(): ?string
    {
        if (blank($this->ip)) {
            return null;
        }

        return mb_substr(trim((string) $this->ip), 0, 45);
    }

    private function normalizedUserAgent(): ?string
    {
        if (blank($this->userAgent)) {
            return null;
        }

        return mb_substr(trim((string) $this->userAgent), 0, 2000);
    }
}
