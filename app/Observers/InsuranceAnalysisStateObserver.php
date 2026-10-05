<?php

namespace App\Observers;

use App\Events\InsuranceAnalysisChanged;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;

class InsuranceAnalysisStateObserver
{
    public function created(InsuranceAnalysis|InsuranceAnalysisBatch $model): void
    {
        $this->notify($model);
    }

    public function updated(InsuranceAnalysis|InsuranceAnalysisBatch $model): void
    {
        $fields = $model instanceof InsuranceAnalysisBatch
            ? ['status', 'total_providers', 'completed_providers', 'failed_providers', 'started_at', 'finished_at']
            : array_diff($model->getFillable(), ['request_payload', 'quote_pdf_path', 'pdf_generated_at', 'email_sent_at']);

        if ($model->wasChanged($fields)) {
            $this->notify($model);
        }
    }

    private function notify(InsuranceAnalysis|InsuranceAnalysisBatch $model): void
    {
        if (config('broadcasting.default') !== 'reverb') {
            return;
        }

        $leadId = (int) $model->lead_id;
        $model->getConnection()->afterCommit(static function () use ($leadId): void {
            rescue(static fn () => InsuranceAnalysisChanged::dispatch($leadId));
        });
    }
}
