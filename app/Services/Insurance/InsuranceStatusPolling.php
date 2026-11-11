<?php

namespace App\Services\Insurance;

use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Jobs\SyncTooAnalysisStatusJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceExecution;

final class InsuranceStatusPolling
{
    public static function stopTooAtLimit(InsuranceExecution $execution, string $attemptId, int $checks): bool
    {
        $limit = max(1, (int) config('services.too.status_check_max_pending_checks', 5));
        if (! $execution->isTooProvider() || ! in_array($execution->status, ['processing', 'manual_review'], true) || $checks < $limit) {
            return false;
        }

        $execution->forceFill(['response_payload' => array_merge($execution->providerResponsePayload(), [
            'too_status_check_stopped' => true,
            'too_status_check_stop_reason' => 'pending_limit_reached',
            'too_status_check_attempts' => $checks,
            'too_status_check_limit' => $limit,
            'too_status_check_stopped_at' => now()->toDateTimeString(),
        ])])->save();
        $execution->events()->create([
            'event_type' => 'too_polling_limit_reached', 'status' => $execution->status,
            'message' => 'Limite de consultas automáticas atingido. A Too ainda não informou uma decisão final.',
            'payload' => ['attempt_id' => $attemptId, 'checks' => $checks, 'limit' => $limit],
        ]);
        app(InsuranceAnalysisLogger::class)->record($execution, $attemptId, []);

        return true;
    }

    public static function schedule(InsuranceAnalysis $analysis, string $attemptId, bool $isReanalysis): void
    {
        if (ProviderAnalysisStatus::isTerminal($analysis->status)
            || ($analysis->currentAttemptContext()['attempt_id'] ?? null) !== $attemptId) {
            return;
        }

        $delay = max(1, (int) config("services.{$analysis->provider}.status_check_delay_seconds", 30));

        if ($analysis->isTooProvider()) {
            SyncTooAnalysisStatusJob::dispatch($analysis->id, $attemptId, $isReanalysis)
                ->delay(now()->addSeconds($delay))->afterCommit();

            return;
        }

        SyncProviderAnalysisStatusJob::dispatch($analysis->id, $attemptId, $isReanalysis, automatic: true)
            ->delay(now()->addSeconds($delay))->afterCommit();
    }
}
