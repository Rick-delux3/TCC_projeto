<?php

namespace App\Services\Insurance;

use App\Models\InsuranceQuote;

final class InsuranceQuotePayment
{
    public function __construct(private PottencialService $pottencial, private TooService $too) {}

    public function fetch(InsuranceQuote $quote): array
    {
        $result = match ($quote->provider) {
            'pottencial' => $this->pottencial->getPaymentSimulations($quote->quote_id),
            'too' => $this->too->getPaymentOptions($quote->quote_id),
            default => throw new \LogicException('Consulta de pagamento não implementada para esta seguradora.'),
        };
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException('Não foi possível consultar as parcelas da cotação.');
        }
        $response = $result['response'] ?? [];
        if (! is_array($response)) {
            throw new \RuntimeException('Resposta de pagamento inválida.');
        }
        $selection = $quote->provider === 'pottencial'
            ? $this->pottencialSelection($response, $quote->payment_type)
            : $this->tooSelection($response, $quote->payment_type);

        return ['response' => $response, 'selection' => $selection];
    }

    private function pottencialSelection(array $response, string $paymentType): ?array
    {
        $options = array_is_list($response) ? $response : [$response];
        foreach ($options as $option) {
            if (($option['paymentType'] ?? null) !== $paymentType) {
                continue;
            }
            foreach ($option['installments'] ?? [] as $plan) {
                if ((int) ($plan['countInstallments'] ?? 0) !== 30) {
                    continue;
                }
                $amounts = $plan['installments'] ?? [];
                $amount = $amounts[0] ?? null;
                if ($this->validAmount($amount) && collect($amounts)->every(fn ($value) => $this->validAmount($value) && round((float) $value, 2) === round((float) $amount, 2))) {
                    return ['amount' => number_format((float) $amount, 2, '.', ''), 'count' => 30, 'payment_type' => $paymentType, 'with_entry' => false, 'details' => $plan];
                }
            }
        }

        return null;
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
