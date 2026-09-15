<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadLoversTagOperation;
use Illuminate\Support\Facades\DB;

final class LeadLoversTagOperationCoordinator
{
    public const PHASE_PENDING = 'pending';

    public const PHASE_POSTING = 'posting';

    public const PHASE_CONFIRMING = 'confirming';

    public const PHASE_SYNCED = 'synced';

    public const PHASE_FAILED = 'failed';

    public const PHASE_BLOCKED = 'blocked';

    public function registerManualDesired(
        int $leadId,
        string $tagKey,
        string $result,
        int $requestLogId,
        int $corretorId
    ): LeadLoversTagOperation {
        return $this->registerDesired($leadId, [
            'desired_source' => 'manual',
            'desired_tag_key' => $tagKey,
            'desired_result' => $result,
            'desired_request_log_id' => $requestLogId,
            'desired_corretor_id' => $corretorId,
            'desired_batch_id' => null,
            'desired_attempt_id' => null,
            'desired_is_reanalysis' => false,
        ], fn (LeadLoversTagOperation $state): bool => $state->desired_source === 'manual'
            && $state->desired_request_log_id === $requestLogId
        );
    }

    public function registerAnalysisDesired(
        int $leadId,
        string $tagKey,
        int $batchId,
        ?string $attemptId,
        bool $isReanalysis
    ): LeadLoversTagOperation {
        return $this->registerDesired($leadId, [
            'desired_source' => 'analysis',
            'desired_tag_key' => $tagKey,
            'desired_result' => null,
            'desired_request_log_id' => null,
            'desired_corretor_id' => null,
            'desired_batch_id' => $batchId,
            'desired_attempt_id' => $attemptId,
            'desired_is_reanalysis' => $isReanalysis,
        ],
            fn (LeadLoversTagOperation $state): bool => $state->desired_source === 'analysis'
                && $state->desired_batch_id === $batchId
                && $state->desired_attempt_id === $attemptId,
            fn (LeadLoversTagOperation $state): bool => $state->desired_source === 'manual'
                && in_array(
                    $state->phase,
                    [self::PHASE_PENDING, self::PHASE_POSTING, self::PHASE_CONFIRMING, self::PHASE_BLOCKED],
                    true
                )
        );
    }

    public function snapshot(int $leadId): ?LeadLoversTagOperation
    {
        return LeadLoversTagOperation::query()
            ->where('lead_id', $leadId)
            ->first();
    }

    public function adoptExistingInflight(
        int $leadId,
        int $version,
        ?array $action,
        bool $outcomeUncertain
    ): ?LeadLoversTagOperation {
        $state = $this->claimBeforePost($leadId, $version);

        if ($state === null) {
            return $this->snapshot($leadId);
        }

        if ($action !== null) {
            return $this->markAccepted($leadId, $version, $action);
        }

        return $outcomeUncertain
            ? $this->markUncertain($leadId, $version)
            : $state;
    }

    public function claimBeforePost(
        int $leadId,
        int $expectedVersion
    ): ?LeadLoversTagOperation {
        return $this->locked($leadId, function (LeadLoversTagOperation $state) use ($expectedVersion) {
            if (
                $state->version !== $expectedVersion
                || $state->inflight_version !== null
                || $state->phase === self::PHASE_BLOCKED
            ) {
                return null;
            }

            $state->forceFill([
                'phase' => self::PHASE_POSTING,
                'inflight_version' => $state->version,
                'inflight_source' => $state->desired_source,
                'inflight_tag_key' => $state->desired_tag_key,
                'inflight_result' => $state->desired_result,
                'inflight_request_log_id' => $state->desired_request_log_id,
                'inflight_corretor_id' => $state->desired_corretor_id,
                'inflight_batch_id' => $state->desired_batch_id,
                'inflight_attempt_id' => $state->desired_attempt_id,
                'inflight_is_reanalysis' => $state->desired_is_reanalysis,
                'action_id' => null,
                'action_status' => null,
                'action_total' => null,
                'outcome_uncertain' => false,
                'post_attempts' => $state->post_attempts + 1,
                'confirmation_checks' => 0,
                'post_started_at' => now(),
                'last_posted_at' => now(),
                'blocked_reason' => null,
            ])->save();

            return $state->fresh();
        });
    }

    public function reclaimUncertainPost(
        int $leadId,
        int $expectedVersion
    ): ?LeadLoversTagOperation {
        return $this->locked($leadId, function (LeadLoversTagOperation $state) use ($expectedVersion) {
            if (
                $state->version !== $expectedVersion
                || $state->inflight_version !== $expectedVersion
                || ! $state->outcome_uncertain
                || $state->phase !== self::PHASE_CONFIRMING
            ) {
                return null;
            }

            $state->forceFill([
                'phase' => self::PHASE_POSTING,
                'post_attempts' => $state->post_attempts + 1,
                'confirmation_checks' => 0,
                'post_started_at' => now(),
                'last_posted_at' => now(),
            ])->save();

            return $state->fresh();
        });
    }

    public function markAccepted(
        int $leadId,
        int $version,
        array $action
    ): ?LeadLoversTagOperation {
        return $this->updateInflight($leadId, $version, [
            'phase' => self::PHASE_CONFIRMING,
            'action_id' => $action['actionId'],
            'action_status' => $action['status'],
            'action_total' => $action['total'],
            'outcome_uncertain' => false,
            'confirmation_checks' => 0,
        ]);
    }

    public function markUncertain(int $leadId, int $version): ?LeadLoversTagOperation
    {
        return $this->updateInflight($leadId, $version, [
            'phase' => self::PHASE_CONFIRMING,
            'outcome_uncertain' => true,
            'confirmation_checks' => 0,
        ]);
    }

    public function markDefiniteRejection(int $leadId, int $version): ?LeadLoversTagOperation
    {
        return $this->locked($leadId, function (LeadLoversTagOperation $state) use ($version) {
            if ($state->inflight_version !== $version) {
                return null;
            }

            $this->clearInflight($state);
            $state->phase = self::PHASE_FAILED;
            $state->save();

            return $state->fresh();
        });
    }

    public function failUnstartedManualDesired(
        int $leadId,
        ?int $version,
        ?int $requestLogId,
        int $corretorId
    ): ?LeadLoversTagOperation {
        if ($version === null && $requestLogId === null) {
            return null;
        }

        return DB::transaction(function () use (
            $leadId,
            $version,
            $requestLogId,
            $corretorId
        ) {
            $lead = Lead::query()->lockForUpdate()->find($leadId);

            if (! $lead instanceof Lead) {
                return null;
            }

            $state = LeadLoversTagOperation::query()
                ->where('lead_id', $leadId)
                ->lockForUpdate()
                ->first();

            if (
                ! $state instanceof LeadLoversTagOperation
                || $state->inflight_version !== null
                || $state->phase !== self::PHASE_PENDING
                || $state->desired_source !== 'manual'
                || ($version !== null && $state->version !== $version)
                || $state->desired_request_log_id !== $requestLogId
                || $state->desired_corretor_id !== $corretorId
            ) {
                return null;
            }

            $state->forceFill([
                'phase' => self::PHASE_FAILED,
                'blocked_reason' => 'local_context_missing',
            ])->save();

            return $state->fresh();
        });
    }

    public function incrementConfirmation(
        int $leadId,
        int $version
    ): ?LeadLoversTagOperation {
        return $this->locked($leadId, function (LeadLoversTagOperation $state) use ($version) {
            if ($state->inflight_version !== $version) {
                return null;
            }

            $state->forceFill([
                'phase' => self::PHASE_CONFIRMING,
                'confirmation_checks' => $state->confirmation_checks + 1,
            ])->save();

            return $state->fresh();
        });
    }

    public function block(int $leadId, int $version, string $reason): ?LeadLoversTagOperation
    {
        return $this->updateInflight($leadId, $version, [
            'phase' => self::PHASE_BLOCKED,
            'blocked_reason' => mb_substr($reason, 0, 191),
        ]);
    }

    public function completeAndDrain(
        int $leadId,
        int $version
    ): ?LeadLoversTagOperation {
        return $this->locked($leadId, function (LeadLoversTagOperation $state) use ($version) {
            if ($state->inflight_version !== $version) {
                return null;
            }

            $wasDesired = $state->version === $version;
            $this->clearInflight($state);
            $state->phase = $wasDesired ? self::PHASE_SYNCED : self::PHASE_PENDING;
            $state->save();

            return $state->fresh();
        });
    }

    public function completeCurrent(
        int $leadId,
        int $version,
        callable $persist
    ): ?LeadLoversTagOperation {
        return $this->locked($leadId, function (LeadLoversTagOperation $state) use ($version, $persist) {
            if (
                $state->version !== $version
                || ($state->inflight_version !== null && $state->inflight_version !== $version)
            ) {
                return null;
            }

            if ($persist($state) !== true) {
                return null;
            }
            $this->clearInflight($state);
            $state->phase = self::PHASE_SYNCED;
            $state->save();

            return $state->fresh();
        });
    }

    public function completeWithoutInflight(
        int $leadId,
        int $version
    ): ?LeadLoversTagOperation {
        return $this->locked($leadId, function (LeadLoversTagOperation $state) use ($version) {
            if (
                $state->version !== $version
                || $state->inflight_version !== null
            ) {
                return null;
            }

            $state->forceFill([
                'phase' => self::PHASE_SYNCED,
                'blocked_reason' => null,
            ])->save();

            return $state->fresh();
        });
    }

    public function markPostingAsUncertain(
        int $leadId,
        int $version
    ): ?LeadLoversTagOperation {
        return $this->locked($leadId, function (LeadLoversTagOperation $state) use ($version) {
            if (
                $state->inflight_version !== $version
                || $state->phase !== self::PHASE_POSTING
            ) {
                return null;
            }

            $state->forceFill([
                'phase' => self::PHASE_CONFIRMING,
                'outcome_uncertain' => true,
            ])->save();

            return $state->fresh();
        });
    }

    private function registerDesired(
        int $leadId,
        array $attributes,
        callable $sameRequest,
        ?callable $shouldPreserveCurrent = null
    ): LeadLoversTagOperation {
        return $this->locked($leadId, function (LeadLoversTagOperation $state) use (
            $attributes,
            $sameRequest,
            $shouldPreserveCurrent
        ) {
            if ($sameRequest($state) || $shouldPreserveCurrent?->__invoke($state)) {
                return $state;
            }

            $hasInflight = $state->inflight_version !== null;
            $state->forceFill(array_merge($attributes, [
                'version' => $state->version + 1,
                'phase' => ! $hasInflight
                    ? self::PHASE_PENDING
                    : $state->phase,
                'blocked_reason' => $hasInflight
                    ? $state->blocked_reason
                    : null,
            ]))->save();

            return $state->fresh();
        });
    }

    private function updateInflight(
        int $leadId,
        int $version,
        array $attributes
    ): ?LeadLoversTagOperation {
        return $this->locked($leadId, function (LeadLoversTagOperation $state) use ($version, $attributes) {
            if ($state->inflight_version !== $version) {
                return null;
            }

            $state->forceFill($attributes)->save();

            return $state->fresh();
        });
    }

    private function locked(int $leadId, callable $callback): mixed
    {
        return DB::transaction(function () use ($leadId, $callback) {
            Lead::query()->lockForUpdate()->findOrFail($leadId);
            $state = LeadLoversTagOperation::query()
                ->where('lead_id', $leadId)
                ->lockForUpdate()
                ->first();

            if (! $state instanceof LeadLoversTagOperation) {
                $state = LeadLoversTagOperation::query()->create([
                    'lead_id' => $leadId,
                    'version' => 0,
                    'phase' => self::PHASE_PENDING,
                ]);
            }

            return $callback($state);
        });
    }

    private function clearInflight(LeadLoversTagOperation $state): void
    {
        $state->forceFill([
            'inflight_version' => null,
            'inflight_source' => null,
            'inflight_tag_key' => null,
            'inflight_result' => null,
            'inflight_request_log_id' => null,
            'inflight_corretor_id' => null,
            'inflight_batch_id' => null,
            'inflight_attempt_id' => null,
            'inflight_is_reanalysis' => false,
            'action_id' => null,
            'action_status' => null,
            'action_total' => null,
            'outcome_uncertain' => false,
            'post_attempts' => 0,
            'confirmation_checks' => 0,
            'post_started_at' => null,
            'last_posted_at' => null,
            'blocked_reason' => null,
        ]);
    }
}
