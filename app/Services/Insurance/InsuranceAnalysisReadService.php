<?php

namespace App\Services\Insurance;

use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InsuranceAnalysisReadService
{
    /**
     * @return array{
     *     lead: Lead,
     *     batch: InsuranceAnalysisBatch|null,
     *     analyses: Collection<int, InsuranceAnalysis>,
     *     analysisAttempts: array<int, array{attempt_id: string, is_reanalysis: bool}|null>,
     *     awaitingBatch: bool
     * }
     */
    public function read(Lead $lead): array
    {
        $lead->load([
            'endereco',
            'despesas',
            'conjuge',
            'latestInsuranceAnalysisBatch.analyses' => fn (HasMany $query): HasMany => $query
                ->orderBy('id')->with([
                    'latestAttemptEvent' => fn (HasOne $query): HasOne => $query->select(
                        $query->getRelated()->qualifyColumns(['id', 'insurance_analysis_id', 'event_type', 'payload'])
                    ),
                ]),
        ]);
        $batch = $lead->latestInsuranceAnalysisBatch;
        $analyses = $batch?->analyses ?? new Collection;

        return [
            'lead' => $lead,
            'batch' => $batch,
            'analyses' => $analyses,
            'analysisAttempts' => $analyses->mapWithKeys(fn (InsuranceAnalysis $analysis): array => [
                $analysis->id => $analysis->currentAttemptContext(),
            ])->all(),
            'awaitingBatch' => $batch === null,
        ];
    }
}
