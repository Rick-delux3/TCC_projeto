<?php

namespace App\Services\Insurance;

use App\Exceptions\ObsoleteInsuranceAnalysisAttempt;
use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use Closure;
use Illuminate\Support\Facades\DB;

class InsuranceAnalysisAttempt
{
    public const START_EVENTS = ['created', 'analysis_restarted', 'analysis_started', 'reanalysis_requested', 'reanalysis_started', 'technical_retry_requested'];

    public static function assertCurrent(InsuranceAnalysis $analysis, ?string $attemptId): void
    {
        $current = $analysis->currentAttemptContext();
        if (! $attemptId || ($current['attempt_id'] ?? null) !== $attemptId) {
            throw new ObsoleteInsuranceAnalysisAttempt('Rodada da análise substituída ou não identificada.');
        }
    }

    public static function run(InsuranceAnalysis $analysis, string $attemptId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($analysis, $attemptId, $callback) {
            $locked = InsuranceAnalysis::query()->lockForUpdate()->find($analysis->id);
            if (! $locked || ($locked->currentAttemptContext(true)['attempt_id'] ?? null) !== $attemptId) {
                throw new ObsoleteInsuranceAnalysisAttempt('Resposta de uma rodada obsoleta descartada.');
            }
            $analysis->setRawAttributes($locked->getAttributes(), true);
            $analysis->executionAttemptId = $attemptId;

            return $callback();
        });
    }

    /** @return array{attempt_id: string, is_reanalysis: bool}|null */
    public static function batchContext(int $batchId, bool $lock = false): ?array
    {
        $event = InsuranceAnalysisEvent::query()
            ->whereHas('analysis', fn ($query) => $query->where('insurance_analysis_batch_id', $batchId))
            ->whereIn('event_type', self::START_EVENTS)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->latest('id')->first();
        $id = data_get($event?->payload, 'attempt_id');

        return is_string($id) && filled($id)
            ? ['attempt_id' => $id, 'is_reanalysis' => (bool) data_get($event->payload, 'is_reanalysis', str_starts_with($event->event_type, 'reanalysis_'))]
            : null;
    }

    public static function dispatchCompletion(InsuranceAnalysis $analysis): void
    {
        if (! $analysis->insurance_analysis_batch_id) {
            return;
        }
        $context = self::batchContext($analysis->insurance_analysis_batch_id);
        if ($context) {
            CompleteInsuranceAnalysesBatchJob::dispatch($analysis->insurance_analysis_batch_id, $context['attempt_id'], $context['is_reanalysis'])->afterCommit();
        }
    }

    public static function runBatch(InsuranceAnalysisBatch $batch, ?string $attemptId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($batch, $attemptId, $callback) {
            $locked = InsuranceAnalysisBatch::query()->lockForUpdate()->find($batch->id);
            if (! $locked || ! $attemptId || (self::batchContext($batch->id, true)['attempt_id'] ?? null) !== $attemptId) {
                throw new ObsoleteInsuranceAnalysisAttempt('Rodada do pacote substituída.');
            }
            $batch->setRawAttributes($locked->getAttributes(), true);

            return $callback();
        });
    }
}
