<?php

namespace App\Services\Insurance;

use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Jobs\SyncTooAnalysisStatusJob;
use App\Models\InsuranceAnalysis;

final class InsuranceStatusPolling
{
    public static function schedule(InsuranceAnalysis $analysis, string $attemptId, bool $isReanalysis): void
    {
        if (ProviderAnalysisStatus::isTerminal($analysis->status)) {
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
