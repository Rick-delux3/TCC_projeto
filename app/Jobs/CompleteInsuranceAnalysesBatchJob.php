<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CompleteInsuranceAnalysesBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $batchId,
        public string $attemptId,
        public bool $isReanalysis = false
    ) {}

    public function handle(): void
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            logger()->notice('Job de análise ignorado porque o módulo está desativado.', ['job' => static::class]);

            return;
        }

        $batch = InsuranceAnalysisBatch::with(['analyses.events'])->findOrFail($this->batchId);

        /*
        |--------------------------------------------------------------------------
        | Uma análise por provider dentro do lote
        |--------------------------------------------------------------------------
        | Como a reanálise reaproveita as análises existentes, o status atual de
        | cada análise representa a rodada atual.
        */
        $completed = $batch->analyses()
            ->whereIn('status', ['quoted', 'approved', 'rejected', 'manual_review'])
            ->count();

        $failed = $batch->analyses()
            ->where('status', 'failed')
            ->count();

        $total = $batch->analyses()->count();

        $status = $failed > 0 && ($completed + $failed) >= $total
            ? 'completed_with_errors'
            : 'completed';

        if (($completed + $failed) < $total) {
            $status = 'processing';
        }

        $batch->update([
            'status' => $status,
            'completed_providers' => $completed,
            'failed_providers' => $failed,
            'finished_at' => $status !== 'processing' ? now() : null,
        ]);

        if ($status === 'processing') {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Evita e-mail/tag duplicados
        |--------------------------------------------------------------------------
        | Este Job pode ser disparado pelo RunProviderAnalysisJob e pelo finally()
        | do Bus::batch. O evento email_queued funciona como trava da rodada.
        */
        $this->queueCompletionJobs($batch);
    }

    /**
     * Persiste a trava e os jobs da fila database na mesma transação.
     */
    private function queueCompletionJobs(InsuranceAnalysisBatch $batch): bool
    {
        return DB::transaction(function () use ($batch) {
            $controlAnalysis = InsuranceAnalysis::query()
                ->where('insurance_analysis_batch_id', $batch->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $controlAnalysis) {
                return false;
            }

            $attemptEvents = InsuranceAnalysisEvent::query()
                ->whereHas('analysis', function ($query) use ($batch) {
                    $query->where('insurance_analysis_batch_id', $batch->id);
                })
                ->where('payload->attempt_id', $this->attemptId);

            if ((clone $attemptEvents)->where('event_type', 'email_sent')->exists()) {
                return false;
            }

            $latestQueuedId = (clone $attemptEvents)
                ->where('event_type', 'email_queued')
                ->max('id');

            $latestReleasedId = (clone $attemptEvents)
                ->whereIn('event_type', ['email_failed', 'email_deferred'])
                ->max('id');

            if ($latestQueuedId && (! $latestReleasedId || $latestQueuedId > $latestReleasedId)) {
                return false;
            }

            $isFirstQueueForAttempt = ! $latestQueuedId;

            $controlAnalysis->events()->create([
                'event_type' => 'email_queued',
                'status' => 'queued',
                'message' => $this->isReanalysis
                    ? 'E-mail com PDFs da reanálise foi colocado na fila.'
                    : 'E-mail com PDFs da análise foi colocado na fila.',
                'payload' => [
                    'attempt_id' => $this->attemptId,
                    'is_reanalysis' => $this->isReanalysis,
                    'batch_id' => $batch->id,
                    'queued_at' => now()->toDateTimeString(),
                ],
            ]);

            $batch->update([
                'email_status' => 'queued',
                'email_failed_at' => null,
                'email_error' => null,
            ]);

            if ($isFirstQueueForAttempt) {
                ApplyFinalAnalysisTagToLeadLoversJob::dispatch(
                    batchId: $batch->id,
                    attemptId: $this->attemptId,
                    isReanalysis: $this->isReanalysis
                )->beforeCommit();
            }

            SendAnalysisResultsEmailJob::dispatch(
                batchId: $batch->id,
                attemptId: $this->attemptId,
                isReanalysis: $this->isReanalysis
            )->beforeCommit();

            return true;
        });
    }
}
