<?php

namespace App\Jobs;

use App\Events\DashboardActivityChanged;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use App\Models\Lead;
use App\Services\Insurance\InsuranceAnalysisAttempt;
use App\Services\Insurance\InsuranceBatchResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CompleteInsuranceAnalysesBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public int $batchId,
        public string $attemptId,
        public bool $isReanalysis = false
    ) {}

    public function handle(): void
    {
        try {
            $this->complete();
        } catch (\App\Exceptions\ObsoleteInsuranceAnalysisAttempt) {
            return;
        }
    }

    private function complete(): void
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            logger()->notice('Job de análise ignorado porque o módulo está desativado.', ['job' => static::class]);

            return;
        }

        $batch = DB::transaction(function (): ?InsuranceAnalysisBatch {
            $leadId = InsuranceAnalysisBatch::query()->whereKey($this->batchId)->value('lead_id');
            $lead = Lead::query()->lockForUpdate()->findOrFail($leadId);
            $batch = InsuranceAnalysisBatch::query()->lockForUpdate()->findOrFail($this->batchId);
            $analyses = $batch->analyses()->lockForUpdate()->get();
            $context = \App\Services\Insurance\InsuranceAnalysisAttempt::batchContext($batch->id, true);
            if (($context['attempt_id'] ?? null) !== $this->attemptId) {
                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Uma análise por provider dentro do lote
            |--------------------------------------------------------------------------
            | Como a reanálise reaproveita as análises existentes, o status atual de
            | cada análise representa a rodada atual.
            */
            $completed = $analyses
                ->whereIn('status', ['approved', 'rejected'])
                ->count();

            $failed = $analyses
                ->where('status', 'failed')
                ->count();

            $total = max($batch->total_providers, $analyses->count());

            $status = $failed > 0 && ($completed + $failed) >= $total
                ? 'completed_with_errors'
                : 'completed';

            if ($total === 0 || ($completed + $failed) < $total) {
                $status = 'processing';
            }

            $batch->update([
                'status' => $status,
                'completed_providers' => $completed,
                'failed_providers' => $failed,
                'finished_at' => $status !== 'processing' ? ($batch->finished_at ?? now()) : null,
            ]);

            if ($status === 'processing') {
                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Evita e-mail/tag duplicados
            |--------------------------------------------------------------------------
            | Este Job pode ser disparado pelo RunProviderAnalysisJob e pelo finally()
            | do Bus::batch. O evento email_queued funciona como trava da rodada.
            */
            $batch->setRelation('analyses', $analyses);
            $alreadyConsolidated = InsuranceAnalysisEvent::query()
                ->whereIn('insurance_analysis_id', $analyses->modelKeys())
                ->where('event_type', 'local_result_consolidated')
                ->where('payload->attempt_id', $this->attemptId)->exists();
            if (! $alreadyConsolidated) {
                $result = InsuranceBatchResult::status($batch);
                $lead->forceFill([
                    'analysis_final_status' => $result,
                    'analysis_final_tag_key' => InsuranceBatchResult::tagKey($batch),
                    'analysis_finalized_at' => $batch->finished_at,
                    'last_analysis_batch_id' => $batch->id,
                    'tags_originais' => InsuranceBatchResult::localTags($lead->tags_originais, $result),
                ])->save();
                $analyses->first()->events()->create([
                    'event_type' => 'local_result_consolidated',
                    'status' => $result,
                    'payload' => ['attempt_id' => $this->attemptId, 'is_reanalysis' => $this->isReanalysis],
                ]);
                DashboardActivityChanged::dispatch('lead', $lead->id, $lead->company_id, 'lead.analysis-result.changed');
            }

            return $batch;
        });
        if (! $batch) {
            return;
        }

        $failure = null;
        try {
            $this->queueLeadLoversSync($batch);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        $this->queueCompletionJobs($batch);
        if ($failure) {
            throw $failure;
        }
    }

    private function queueLeadLoversSync(InsuranceAnalysisBatch $batch): void
    {
        if (! config('services.leadlovers.enabled', false)) {
            return;
        }
        InsuranceAnalysisAttempt::runBatch($batch, $this->attemptId, function () use ($batch): void {
            $batch->load('lead', 'analyses');
            if (! InsuranceBatchResult::readyForLeadLovers($batch->lead, $batch)) {
                return;
            }
            $analysis = $batch->analyses->first();
            if ($analysis->events()->where('event_type', 'leadlovers_final_sync_queued')
                ->where('payload->attempt_id', $this->attemptId)->exists()) {
                return;
            }
            if ((int) $batch->lead->leadlovers_lead_id > 0) {
                ApplyFinalAnalysisTagToLeadLoversJob::dispatch($batch->id, $this->attemptId, $this->isReanalysis)->beforeCommit();
            } else {
                SendLeadToLeadLoversJob::dispatch($batch->lead_id)->beforeCommit();
            }
            $analysis->events()->create([
                'event_type' => 'leadlovers_final_sync_queued',
                'status' => 'queued',
                'payload' => ['attempt_id' => $this->attemptId, 'is_reanalysis' => $this->isReanalysis],
            ]);
        });
    }

    /**
     * Persiste a trava e os jobs da fila database na mesma transação.
     */
    private function queueCompletionJobs(InsuranceAnalysisBatch $batch): bool
    {
        return InsuranceAnalysisAttempt::runBatch($batch, $this->attemptId, function () use ($batch) {
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

            SendAnalysisResultsEmailJob::dispatch(
                batchId: $batch->id,
                attemptId: $this->attemptId,
                isReanalysis: $this->isReanalysis
            )->afterCommit();

            return true;
        });
    }
}
