<?php

namespace App\Services\Insurance\Providers;

class TokioInsuranceProvider implements ReanalyzesInsuranceQuotes
{
    public function __construct(
        private \App\Services\Insurance\TokioService $service,
        private \App\Services\Insurance\Payloads\TokioQuotePayloadBuilder $builder,
    ) {}

    public function name(): string
    {
        return 'tokio';
    }

    public function requestAnalysis(\App\Models\InsuranceExecution $analysis, string $attemptId): array
    {
        if (! $analysis instanceof \App\Models\InsuranceQuote) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('A Tokio exige uma cotação vinculada à rodada de análise.');
        }
        $analysis->executionAttemptId = $attemptId;
        \App\Services\Insurance\InsuranceAnalysisAttempt::assertCurrent($analysis, $attemptId);
        if (strlen($analysis->lead->rentalApplicantDocument()) === 14 || $analysis->lead->tipo_pessoa === 'J') {
            return ['success' => true, 'response' => [], 'manual_required' => true];
        }
        $payload = $this->builder->build($analysis);
        $partner = data_get($analysis->scenario->providerRun->round->lead_snapshot, 'tokio.partner_code');
        $analysis->updateForAttempt(['request_payload' => $payload]);

        return $this->service->quote($payload, filled($partner) ? (string) $partner : null);
    }

    public function requestReanalysis(\App\Models\InsuranceExecution $analysis, string $attemptId, array $options = []): array
    {
        return $this->requestAnalysis($analysis, $attemptId);
    }

    public function getStatus(\App\Models\InsuranceExecution $analysis): array
    {
        $stored = $analysis->providerResponsePayload();
        if (is_array($stored['response'] ?? null) && filled(data_get($stored, 'response.cotacaoInfo.cdSitucCotac'))) {
            return ['success' => true, 'response' => $stored['response']];
        }

        return ['success' => false, 'error' => 'A Tokio não possui consulta de andamento documentada. Confira a tentativa antes de iniciar nova cotação.', 'response' => []];
    }
}
