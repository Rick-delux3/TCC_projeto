<?php

namespace App\Services\Insurance;

use App\Models\InsuranceAnalysisBatch;
use App\Services\PottencialService;
use App\Services\TooService;
use RuntimeException;

class ProviderRefusalLetterService
{
    public function fetch(InsuranceAnalysisBatch $batch, string $attemptId, int $analysisId): string
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
            default => throw new RuntimeException('Companhia sem integração de carta de recusa.'),
        };

        InsuranceAnalysisAttempt::runBatch($batch, $attemptId, fn (): bool => true);

        return $contents;
    }
}
