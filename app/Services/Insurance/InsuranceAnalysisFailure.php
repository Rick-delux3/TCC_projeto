<?php

namespace App\Services\Insurance;

use App\Exceptions\ObsoleteInsuranceAnalysisAttempt;
use App\Models\InsuranceAnalysis;
use Throwable;

class InsuranceAnalysisFailure
{
    public static function finish(int $analysisId, ?string $attemptId, bool $isReanalysis, ?Throwable $exception): void
    {
        $analysis = InsuranceAnalysis::query()->find($analysisId);
        if (! $analysis || ! $attemptId) {
            return;
        }

        try {
            InsuranceAnalysisAttempt::run($analysis, $attemptId, function () use ($analysis, $attemptId, $isReanalysis, $exception): void {
                if (ProviderAnalysisStatus::isTerminal($analysis->status)) {
                    return;
                }

                $analysis->update([
                    'status' => 'failed',
                    'result' => null,
                    'error_message' => $exception instanceof \App\Exceptions\InvalidInsuranceAnalysisPayload
                        ? $exception->getMessage()
                        : 'Não foi possível concluir a análise após as tentativas de processamento.',
                    'finished_at' => now(),
                ]);
                $analysis->events()->create([
                    'event_type' => $isReanalysis ? 'reanalysis_failed' : 'failed',
                    'status' => 'failed',
                    'message' => $analysis->error_message,
                    'payload' => [
                        'attempt_id' => $attemptId,
                        'is_reanalysis' => $isReanalysis,
                        'exception' => $exception ? $exception::class : null,
                    ],
                ]);
                InsuranceAnalysisAttempt::dispatchCompletion($analysis);
            });
        } catch (ObsoleteInsuranceAnalysisAttempt) {
            return;
        }
    }
}
