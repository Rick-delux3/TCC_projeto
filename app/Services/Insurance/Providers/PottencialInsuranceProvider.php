<?php

namespace App\Services\Insurance\Providers;

use App\Models\InsuranceAnalysis;
use App\Services\Insurance\Payloads\RentalGuaranteeQuotePayloadBuilder;
use App\Services\PottencialService;

class PottencialInsuranceProvider implements InsuranceProviderInterface
{
    public function __construct(
        private readonly PottencialService $pottencialService,
        private readonly RentalGuaranteeQuotePayloadBuilder $payloadBuilder
    ) {
    }

    public function name(): string
    {
        return 'Pottencial';
    }

    public function requestAnalysis(
        InsuranceAnalysis $analysis,
        string $attemptId
    ): array {
        return $this->requestQuote(
            analysis: $analysis,
            attemptId: $attemptId,
            isReanalysis: false
        );
    }

    public function requestReanalysis(
        InsuranceAnalysis $analysis,
        string $attemptId,
        array $options = []
    ): array {
        $analysis->events()->create([
            'event_type' => 'pottencial_reanalysis_fallback',
            'status' => 'processing',
            'message' => 'A Pottencial ainda não possui fluxo oficial de reanálise. Uma nova cotação externa será solicitada.',
            'payload' => [
                'attempt_id' => $attemptId,
                'is_reanalysis' => true,
                'fallback' => 'request_analysis',
                'options' => $options,
            ],
        ]);

        return $this->requestQuote(
            analysis: $analysis,
            attemptId: $attemptId,
            isReanalysis: true
        );
    }

    public function getStatus(InsuranceAnalysis $analysis): array
    {
        if (empty($analysis->quote_id)) {
            throw new \RuntimeException(
                'A análise da Pottencial não possui quote_id para consultar o status.'
            );
        }

        return $this->pottencialService->getRentalGuaranteeQuote(
            $analysis->quote_id
        );
    }

    private function requestQuote(
        InsuranceAnalysis $analysis,
        string $attemptId,
        bool $isReanalysis
    ): array {
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
        $analysis->update([
            'request_payload' => $payload,
        ]);

        $analysis->events()->create([
            'event_type' => $isReanalysis
                ? 'pottencial_quote_restarted'
                : 'pottencial_quote_started',
            'status' => 'processing',
            'message' => $isReanalysis
                ? 'Nova cotação externa da Pottencial iniciada como fallback da reanálise.'
                : 'Cotação da Pottencial iniciada.',
            'payload' => [
                'attempt_id' => $attemptId,
                'is_reanalysis' => $isReanalysis,
            ],
        ]);

        return $this->pottencialService->createRentalGuaranteeQuote(
            $payload
        );
    }
}