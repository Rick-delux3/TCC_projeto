<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysisBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CompleteInsuranceAnalysesBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $batchId
    ) {}

    public function handle(): void
    {
        $batch = InsuranceAnalysisBatch::with('analyses')->findOrFail($this->batchId);

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

        if ($status !== 'processing') {
            SendAnalysisResultsEmailJob::dispatch($batch->id);
        }
    }
}