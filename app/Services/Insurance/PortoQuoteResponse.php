<?php

namespace App\Services\Insurance;

use App\Models\InsuranceExecution;

/**
 * Interpreta as respostas da Porto: decisão do orçamento, normalização das
 * ofertas e seleção da condição de pagamento. Não faz chamadas HTTP.
 */
class PortoQuoteResponse
{
    private const DEFAULT_REFUSAL = 'Orçamento recusado pelos critérios de aceitação da Porto.';

    /**
     * POST /orcamentos: 201 aprova, 409 recusa e qualquer outro resultado é
     * falha técnica — nunca recusa. O número do orçamento é gravado aqui, antes
     * de qualquer etapa seguinte.
     */
    public function apply(InsuranceExecution $execution, array $result): void
    {
        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $http = $result['http_status'] ?? null;
        $decided = ($result['success'] ?? false) === true;

        // Aprovação traz o orçamento em "orcamento"; a recusa, em "detailedMessage".
        $budget = self::budgetNumber($http === 409 ? ($response['detailedMessage'] ?? null) : ($response['orcamento'] ?? null));

        $status = match (true) {
            $decided && $http === 201 && $budget !== null => 'approved',
            $decided && $http === 409 => 'rejected',
            default => 'failed',
        };

        $reason = null;
        if ($status === 'rejected') {
            $message = $response['errorMessage'] ?? null;
            $reason = is_string($message) && trim($message) !== '' ? mb_substr(trim($message), 0, 500) : self::DEFAULT_REFUSAL;
        }
        $error = match (true) {
            $status !== 'failed' => null,
            $decided && $http === 201 => 'A Porto aprovou o orçamento sem informar um número válido. Verifique antes de criar outro orçamento.',
            default => $result['error'] ?? 'Resposta Porto incompleta ou inválida.',
        };

        $execution->forceFill([
            'status' => $status,
            'result' => ProviderAnalysisStatus::result($status),
            'provider_status' => $status === 'failed' ? null : (string) $http,
            'quote_id' => $status === 'failed' ? null : $budget,
            'rejection_reason' => $reason,
            'response_payload' => [
                'response' => $response,
                'http_status' => $http,
                'errors' => $result['errors'] ?? [],
                // Recusa sem orçamento válido continua recusa; apenas não há carta.
                'budget_missing' => $status === 'rejected' && $budget === null,
            ],
            'error_message' => $error,
            'finished_at' => now(),
        ])->save();

        $execution->events()->create([
            'event_type' => 'porto_result',
            'status' => $status,
            'message' => match ($status) {
                'approved' => 'Orçamento aprovado pela Porto.',
                'rejected' => $reason,
                default => 'Não foi possível concluir o orçamento na Porto.',
            },
            'payload' => ['attempt_id' => $execution->executionAttemptId, 'http_status' => $http],
        ]);
    }

    /** Orçamento Porto: string de 15 dígitos, preservando zeros à esquerda. */
    public static function budgetNumber(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9]{15}$/D', $value) === 1 ? $value : null;
    }

    /**
     * Códigos das ofertas retornadas na criação (a de comparação recebe o ajuste de comissão).
     *
     * @return list<string>
     */
    public function offerCodes(array $response): array
    {
        $codes = [];
        foreach (is_array($response['ofertas'] ?? null) ? $response['ofertas'] : [] as $offer) {
            $code = is_array($offer) ? ($offer['seguro'] ?? null) : null;
            if (is_string($code) && preg_match('/^[0-9]{4}$/D', $code) === 1 && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Normaliza ofertas, formas e condições preservando o conjunto
     * produto + forma + condição + parcela de cada alternativa.
     *
     * O GET de ofertas traz condicoesPagamento como objeto e o GET do orçamento
     * como lista; as duas formas são tratadas explicitamente e listas vazias ou
     * nulas apenas não produzem alternativa.
     *
     * @return list<array{offer_code: string, offer_name: string|null, payment_method: string, payment_method_name: string|null, condition_code: string, count: int, amount: string, details: array}>
     */
    public function alternatives(array $response): array
    {
        $alternatives = [];
        foreach (is_array($response['ofertas'] ?? null) ? $response['ofertas'] : [] as $offer) {
            $code = is_array($offer) ? ($offer['seguro'] ?? null) : null;
            if (! is_string($code) || preg_match('/^[0-9]{4}$/D', $code) !== 1) {
                continue;
            }
            foreach (is_array($offer['formasPagamento'] ?? null) ? $offer['formasPagamento'] : [] as $method) {
                $methodCode = is_array($method) ? ($method['formaPagamento'] ?? null) : null;
                if (! is_string($methodCode) || trim($methodCode) === '') {
                    continue;
                }
                $conditions = $method['condicoesPagamento'] ?? null;
                if (! is_array($conditions) || $conditions === []) {
                    continue;
                }
                foreach (array_is_list($conditions) ? $conditions : [$conditions] as $condition) {
                    $alternative = is_array($condition) ? $this->condition($condition) : null;
                    if ($alternative !== null) {
                        $alternatives[] = [
                            'offer_code' => $code,
                            'offer_name' => is_string($offer['nomeSeguro'] ?? null) ? trim($offer['nomeSeguro']) : null,
                            'payment_method' => strtoupper(trim($methodCode)),
                            'payment_method_name' => is_string($method['nomeFormaPagamento'] ?? null) ? trim($method['nomeFormaPagamento']) : null,
                        ] + $alternative;
                    }
                }
            }
        }

        return $alternatives;
    }

    /**
     * Comissão informada pelo GET de ofertas para a oferta, em pontos
     * percentuais (ofertas[].comissao.codComissao). Igual à enviada no PUT indica
     * que esse GET já reflete o ajuste. Null quando a oferta ou o campo não
     * vierem como inteiro.
     */
    public function commissionCode(array $response, string $offerCode): ?int
    {
        foreach (is_array($response['ofertas'] ?? null) ? $response['ofertas'] : [] as $offer) {
            if (is_array($offer) && ($offer['seguro'] ?? null) === $offerCode) {
                $code = $offer['comissao']['codComissao'] ?? null;

                return is_int($code) ? $code : null;
            }
        }

        return null;
    }

    /**
     * Escolhe, por oferta, a condição da forma de pagamento esperada com a
     * quantidade de pagamentos Sem entrada.
     *
     * Somente a oferta de comparação (Fiança), com a comissão confirmada, vira
     * "selection". As demais ficam em "alternatives" com a comissão padrão da
     * Porto e nunca substituem o preço comparado.
     *
     * @param  bool  $commissionConfirmed  comissão da oferta de comparação confirmada pelo HTTP 200 do PUT
     */
    public function payment(array $response, string $paymentType, bool $commissionConfirmed): array
    {
        $count = (int) config('services.porto.installments_without_entry', 29);
        $comparison = (string) config('services.porto.comparison_offer', '0008');

        $byOffer = [];
        foreach ($this->alternatives($response) as $alternative) {
            if ($alternative['payment_method'] !== $paymentType || $alternative['count'] !== $count) {
                continue;
            }
            $current = $byOffer[$alternative['offer_code']] ?? null;
            if ($current === null
                || ((float) $alternative['amount'] <=> (float) $current['amount'] ?: strcmp($alternative['condition_code'], $current['condition_code'])) < 0) {
                $byOffer[$alternative['offer_code']] = $alternative;
            }
        }

        $format = fn (array $alternative): array => [
            'amount' => $alternative['amount'], 'count' => $alternative['count'],
            'payment_type' => $paymentType, 'with_entry' => false,
        ] + $alternative;

        $selection = $commissionConfirmed && isset($byOffer[$comparison]) ? $format($byOffer[$comparison]) : null;
        unset($byOffer[$comparison]);
        ksort($byOffer, SORT_STRING);

        return [
            'selection' => $selection,
            'alternatives' => array_values(array_map($format, $byOffer)),
            'unavailable_reason' => $selection === null
                ? "Não foi identificada a oferta {$comparison} com comissão confirmada em {$count} parcelas na forma de pagamento {$paymentType}."
                : null,
        ];
    }

    private function condition(array $condition): ?array
    {
        $code = $condition['condicaoPagamento'] ?? null;
        $count = filter_var($condition['quantidadeParcela'] ?? null, FILTER_VALIDATE_INT);
        $amount = $condition['valorParcela'] ?? null;

        // Quantidade e preço precisam ser utilizáveis: nada é calculado
        // dividindo o prêmio total nem inferido do nome da condição.
        // Condição: três dígitos com zeros à esquerda, usada também no caminho do PDF.
        if (! is_string($code) || preg_match('/^[0-9]{3}$/D', $code) !== 1 || $count === false || $count <= 0
            || (! is_int($amount) && ! is_float($amount) && ! (is_string($amount) && is_numeric($amount)))
            || ! is_finite((float) $amount) || (float) $amount <= 0) {
            return null;
        }

        return [
            'condition_code' => $code,
            'count' => $count,
            'amount' => number_format((float) $amount, 2, '.', ''),
            'details' => $condition,
        ];
    }
}
