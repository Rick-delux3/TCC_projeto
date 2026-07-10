<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysisBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Jobs\SendAnalysisResultsEmailJob;
use App\Jobs\ApplyFinalAnalysisTagToLeadLoversJob;

class CompleteInsuranceAnalysesBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $batchId,
        public ?string $attemptId = null,
        public bool $isReanalysis = false
    ) {}

    public function handle(): void
    {
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
        if (!$this->markEmailAsQueued($batch)) {
            return;
        }

        ApplyFinalAnalysisTagToLeadLoversJob::dispatch(
            batchId: $batch->id,
            attemptId: $this->attemptId,
            isReanalysis: $this->isReanalysis
        );

        /*
        |--------------------------------------------------------------------------
        | ATENÇÃO
        |--------------------------------------------------------------------------
        | Atualize o construtor do SendAnalysisResultsEmailJob para receber:
        | - int $batchId
        | - ?string $attemptId = null
        | - bool $isReanalysis = false
        |
        | O e-mail deve buscar eventos com payload->attempt_id para gerar os PDFs
        | corretos da análise/reanálise atual.
        */
        SendAnalysisResultsEmailJob::dispatch(
            batchId: $batch->id,
            attemptId: $this->attemptId,
            isReanalysis: $this->isReanalysis
        );
    }

    private function markEmailAsQueued(InsuranceAnalysisBatch $batch): bool
    {
        $controlAnalysis = $batch->analyses->first();

        if (!$controlAnalysis) {
            return false;
        }

        if ($this->attemptId) {
            $alreadyQueued = $controlAnalysis->events()
                ->where('event_type', 'email_queued')
                ->where('payload->attempt_id', $this->attemptId)
                ->exists();

            if ($alreadyQueued) {
                return false;
            }
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

        return true;
    }
}
