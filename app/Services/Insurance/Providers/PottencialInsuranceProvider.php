<?php

namespace App\Services\Insurance\Providers;

use App\Models\InsuranceExecution;
use App\Services\Insurance\InsuranceAnalysisAttempt;
use App\Services\Insurance\Payloads\RentalGuaranteeQuotePayloadBuilder;
use App\Services\Insurance\PottencialService;

class PottencialInsuranceProvider implements CreatesInsuranceQuotes, InsuranceProviderInterface
{
    public function __construct(
        private readonly PottencialService $pottencialService,
        private readonly RentalGuaranteeQuotePayloadBuilder $payloadBuilder
    ) {}

    public function name(): string
    {
        return 'Pottencial';
    }

    public function createQuote(InsuranceExecution $analysis): array
    {
        $this->ensureEnabled();

        return $this->pottencialService->createRentalGuaranteeQuote($this->payloadBuilder->build($analysis));
    }

    public function requestAnalysis(
        InsuranceExecution $analysis,
        string $attemptId
    ): array {
        $this->ensureEnabled();
        $analysis->executionAttemptId = $attemptId;
        InsuranceAnalysisAttempt::assertCurrent($analysis, $analysis->executionAttemptId);

        return $this->requestQuote($analysis, $attemptId);
    }

    public function getStatus(InsuranceExecution $analysis): array
    {
        $this->ensureEnabled();
        $analysis->executionAttemptId ??= $analysis->currentAttemptContext()['attempt_id'] ?? null;
        InsuranceAnalysisAttempt::assertCurrent($analysis, $analysis->executionAttemptId);

        if (empty($analysis->quote_id)) {
            throw new \RuntimeException(
                'A análise da Pottencial não possui quote_id para consultar o status.'
            );
        }

        return $this->pottencialService->getRentalGuaranteeQuote(
            $analysis->quote_id
        );
    }

    /** A Pottencial não aceita reanálise: cada rodada é uma cotação nova do objeto de risco. */
    private function requestQuote(InsuranceExecution $analysis, string $attemptId): array
    {
        $this->ensureEnabled();

        $analysis->loadMissing([
            'lead',
            'lead.endereco',
            'lead.company',
        ]);

        $payload = $this->payloadBuilder->build($analysis);

        /*
         * O request_payload deve continuar contendo somente o payload
         * utilizado pela API. attempt_id é armazenado nos eventos.
         */
        $analysis->updateForAttempt([
            'request_payload' => $payload,
        ]);

        InsuranceAnalysisAttempt::run($analysis, $attemptId, function () use ($analysis, $attemptId): void {
            $analysis->events()->create([
                'event_type' => 'pottencial_quote_started',
                'status' => 'processing',
                'message' => 'Cotação da Pottencial iniciada.',
                'payload' => [
                    'attempt_id' => $attemptId,
                    'is_reanalysis' => false,
                ],
            ]);
        });

        return $this->pottencialService->createRentalGuaranteeQuote(
            $payload
        );
    }

    private function ensureEnabled(): void
    {
        if (! config('features.insurance_analysis.enabled', false)
            || ! config('services.pottencial.enabled', false)) {
            throw new \LogicException('O provider pottencial está desativado.');
        }
    }
}
