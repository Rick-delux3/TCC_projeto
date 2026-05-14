<?php

namespace App\Services\Insurance\Providers;

use App\Models\InsuranceAnalysis;
use App\Services\PottencialService;
use App\Services\Insurance\Payloads\RentalGuaranteeQuotePayloadBuilder;

class PottencialInsuranceProvider implements InsuranceProviderInterface
{
    public function __construct(
        private PottencialService $pottencialService,
        private RentalGuaranteeQuotePayloadBuilder $payloadBuilder
    ) {}

    public function name(): string
    {
        return 'pottencial';
    }

    public function requestAnalysis(InsuranceAnalysis $analysis): array
    {
        $payload = $this->payloadBuilder->build($analysis);

        $analysis->update([
            'request_payload' => $payload,
        ]);

        return $this->pottencialService->createRentalGuaranteeQuote($payload);
    }

    public function getStatus(InsuranceAnalysis $analysis): array
    {
        return $this->pottencialService->getRentalGuaranteeQuote($analysis->quote_id);
    }
}