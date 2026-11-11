<?php

namespace App\Services\Insurance;

use App\Models\InsuranceQuote;

final class InsuranceQuotePayment
{
    public function __construct(private PottencialService $pottencial, private TooService $too) {}

    public function fetch(InsuranceQuote $quote): array
    {
        if ($quote->provider === 'tokio') {
            return app(TokioQuoteResponse::class)->payment($quote);
        }
        if ($quote->provider === 'porto') {
            return app(Providers\PortoInsuranceProvider::class)->payment($quote);
        }
        $result = match ($quote->provider) {
            'pottencial' => $this->pottencial->getPaymentSimulations($quote->quote_id),
            'too' => $this->too->getPaymentOptions($quote->quote_id),
            default => throw new \LogicException('Consulta de pagamento não implementada para esta seguradora.'),
        };
        if (! ($result['success'] ?? false)) {
            app(InsuranceAnalysisLogger::class)->record($quote, $quote->executionAttemptId ?? '', $result, payment: true);
            throw new \RuntimeException('Não foi possível consultar as parcelas da cotação.');
        }
        $response = $result['response'] ?? [];
        if (! is_array($response)) {
            app(InsuranceAnalysisLogger::class)->record($quote, $quote->executionAttemptId ?? '', $result, payment: true);
            throw new \RuntimeException('Resposta de pagamento inválida.');
        }
        // Pottencial: primeira parcela da alternativa de 30 (ver PottencialQuoteResponse).
        $selection = $quote->provider === 'pottencial'
            ? app(PottencialQuoteResponse::class)->paymentSelection($response, $quote->payment_type)
            : $this->tooSelection($response, $quote->payment_type);

        return ['response' => $response, 'selection' => $selection];
    }

    private function tooSelection(array $response, string $paymentType): ?array
    {
        $type = $paymentType === 'Invoice' ? 2 : 1;
        if (! collect($response['tipoPagamento'] ?? [])->contains(fn ($item) => (int) ($item['tipo'] ?? 0) === $type)) {
            return null;
        }
        foreach ($response['opcoesParcelamento'] ?? [] as $group) {
            if ((int) ($group['tipo'] ?? 0) !== 2 || ($group['comEntrada'] ?? null) !== false) {
                continue;
            }
            foreach ($group['parcelas'] ?? [] as $plan) {
                if ((int) ($plan['numeroParcela'] ?? 0) === 29 && $this->validAmount($plan['valor'] ?? null)) {
                    return ['amount' => number_format((float) $plan['valor'], 2, '.', ''), 'count' => 29, 'payment_type' => $paymentType, 'with_entry' => false, 'details' => $group];
                }
            }
        }

        return null;
    }

    private function validAmount(mixed $amount): bool
    {
        return is_numeric($amount) && is_finite((float) $amount) && (float) $amount > 0;
    }
}
