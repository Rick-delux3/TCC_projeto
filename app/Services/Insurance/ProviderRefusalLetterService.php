<?php

namespace App\Services\Insurance;

use App\Models\InsuranceAnalysisBatch;
use RuntimeException;

class ProviderRefusalLetterService
{
    /**
     * @param  string|null  $budget  Porto: orçamento recusado do cenário (cada cenário tem sua carta).
     */
    public function fetch(InsuranceAnalysisBatch $batch, string $attemptId, int $analysisId, ?string $budget = null): string
    {
        $reference = InsuranceAnalysisAttempt::runBatch($batch, $attemptId, function () use ($batch, $attemptId, $analysisId): array {
            $prepared = app(AnalysisResultPreparationService::class)->prepare($batch, $attemptId);
            if ($prepared['document_type'] !== 'refusal_letters'
                || collect($prepared['analyses'])->contains(fn (array $analysis): bool => $analysis['status'] !== 'rejected')) {
                throw new RuntimeException('Cartas de recusa exigem recusa de todas as companhias.');
            }
            $reference = collect($prepared['analyses'])->firstWhere('id', $analysisId);
            if (! $reference) {
                throw new RuntimeException('Análise não pertence ao pacote preparado.');
            }
            $analysis = $batch->analyses->firstWhere('id', $analysisId);
            $reference['cpf'] = data_get($analysis->request_payload, 'ficha_payload.pretendentes.0.cpf')
                ?? data_get($analysis->request_payload, 'pretendentes.0.cpf');

            return $reference;
        });

        $contents = match ($reference['provider']) {
            'pottencial' => filled($reference['quote_id'])
                ? app(PottencialService::class)->getRentalGuaranteeRefusalLetter($reference['quote_id'])
                : throw new RuntimeException('Cotação da Pottencial não identificada.'),
            'too' => filled($reference['cpf']) && filled($reference['proposal_id'])
                ? app(TooService::class)->getCreditOpinionPdf($reference['cpf'], $reference['proposal_id'])
                : throw new RuntimeException('CPF original ou proposta da Too não identificados.'),
            'porto' => $budget !== null && in_array($budget, $this->portoRefusedBudgets($batch, $attemptId, $analysisId), true)
                ? app(PortoService::class)->refusalLetter($budget)
                : throw new RuntimeException('Orçamento recusado da Porto não identificado nesta rodada.'),
            default => throw new RuntimeException('Companhia sem integração de carta de recusa.'),
        };

        InsuranceAnalysisAttempt::runBatch($batch, $attemptId, fn (): bool => true);

        return $contents;
    }

    /**
     * Orçamentos recusados da Porto na rodada, um por cenário (principal,
     * cônjuge e conjunta). Recusas sem orçamento válido não geram carta.
     *
     * @return list<string>
     */
    public function portoRefusedBudgets(InsuranceAnalysisBatch $batch, string $attemptId, int $analysisId): array
    {
        $analysis = $batch->analyses()->whereKey($analysisId)->where('provider', 'porto')->first();
        $run = $analysis ? app(InsuranceQuoteCoordinator::class)->findRun($analysis, $attemptId) : null;
        if (! $run) {
            return [];
        }

        return $run->scenarios()->orderBy('id')->with('quotes')->get()
            ->flatMap(fn ($scenario) => $scenario->quotes->where('status', 'rejected'))
            ->map(fn ($quote) => PortoQuoteResponse::budgetNumber($quote->quote_id))
            ->filter()->unique()->values()->all();
    }
}
