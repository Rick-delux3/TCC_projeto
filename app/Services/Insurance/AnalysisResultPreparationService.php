<?php

namespace App\Services\Insurance;

use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\InsuranceAnalysisEvent;
use RuntimeException;

class AnalysisResultPreparationService
{
    public function __construct(
        private readonly AnalysisResultRecipients $recipients,
        private readonly AnalysisQuoteSummary $quotes,
        private readonly AnalysisQuoteComparison $comparison,
    ) {}

    /** @return array<string, mixed> Immutable delivery data for the current batch attempt. */
    public function prepare(InsuranceAnalysisBatch $batch, string $attemptId): array
    {
        return InsuranceAnalysisAttempt::runBatch($batch, $attemptId, function () use ($batch, $attemptId): array {
            $analyses = $batch->analyses()->orderBy('id')->lockForUpdate()->get();
            $batch->setRelation('analyses', $analyses);

            if (! in_array($batch->status, ['completed', 'completed_with_errors'], true)
                || ! $batch->finished_at || $analyses->isEmpty()
                || $analyses->count() < $batch->total_providers
                || $analyses->contains(fn (InsuranceAnalysis $analysis): bool => ! in_array($analysis->status, ['approved', 'rejected', 'failed'], true))) {
                throw new RuntimeException('O pacote ainda não está concluído para preparar o envio.');
            }

            $existing = InsuranceAnalysisEvent::query()
                ->whereIn('insurance_analysis_id', $analyses->modelKeys())
                ->where('event_type', 'result_prepared')
                ->where('payload->attempt_id', $attemptId)->first();

            if ($existing) {
                return $existing->payload['prepared'];
            }

            $batch->load('lead.company.setores', 'lead.imobiliariaInformada', 'lead.locador');
            $recipients = $this->recipients->resolve($batch->lead);
            if ($recipients['to'] === []) {
                throw new RuntimeException('Nenhum destinatário válido encontrado para envio do resultado.');
            }

            $quotes = $analyses->map(fn (InsuranceAnalysis $analysis): array => $this->quotes->summarize($analysis));
            $comparison = $this->comparison->compare($quotes);
            $comparisonIssue = $comparison['comparison_issue'];
            $best = $comparison['best_quote'];
            $result = InsuranceBatchResult::status($batch);
            $context = InsuranceAnalysisAttempt::batchContext($batch->id);
            $prepared = [
                'version' => 1,
                'batch_id' => $batch->id,
                'attempt_id' => $attemptId,
                'is_reanalysis' => $context['is_reanalysis'],
                'prepared_at' => now()->toIso8601String(),
                'lead' => $batch->lead->only(['id', 'nome', 'email', 'company_id', 'tipo_solicitante', 'tipo_locacao']),
                'recipients' => $recipients,
                'result' => $result,
                'document_type' => match ($result) {
                    'approved' => 'own_pdf',
                    'rejected' => 'refusal_letters',
                    default => 'none',
                },
                'comparison_issue' => $comparisonIssue,
                'quotes' => $quotes->all(),
                'best_quote' => $best,
                'other_quotes' => $quotes->reject(fn (array $quote): bool => $quote['analysis_id'] === ($best['analysis_id'] ?? null))
                    ->map(fn (array $quote): array => \Illuminate\Support\Arr::except($quote, ['coverages']))->values()->all(),
                'analyses' => $analyses->map(fn (InsuranceAnalysis $analysis): array => $analysis->only([
                    'id', 'insurance_analysis_batch_id', 'lead_id', 'provider', 'product', 'status', 'provider_status',
                    'quote_id', 'quote_number', 'proposal_id', 'product_key', 'rent_amount', 'charges_amount',
                    'total_monthly_amount', 'premium_amount', 'commercial_premium', 'gross_premium', 'iof', 'insured_amount',
                ]))->all(),
            ];

            $analyses->first()->events()->create([
                'event_type' => 'result_prepared',
                'status' => $result,
                'payload' => ['attempt_id' => $attemptId, 'prepared' => $prepared],
            ]);

            return $prepared;
        });
    }
}
