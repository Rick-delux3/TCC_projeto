<?php

namespace App\Services\Insurance;

/**
 * Interpreta os retornos da Pottencial que decidem planos e parcelas.
 * Não faz chamadas HTTP.
 *
 * - Somente Complete e Traditional são cotados (o Basic deixou de ser usado).
 * - O HTTP 400 "plano indisponível para esta cotação" descarta o plano: não é
 *   oferta, não é falha técnica e não entra na comparação. Os demais 400
 *   continuam sendo falha técnica.
 * - O preço é a primeira parcela da alternativa de 30 parcelas, que concentra
 *   o resíduo de arredondamento do prêmio (ex.: 179,58 + 29 × 179,43).
 */
final class PottencialQuoteResponse
{
    /** @var list<string> */
    public const PLANS = ['Complete', 'Traditional'];

    /** Status local do plano descartado pela Pottencial. */
    public const PLAN_UNAVAILABLE = 'plan_unavailable';

    public const INSTALLMENTS = 30;

    private const PLAN_UNAVAILABLE_MESSAGE = 'the chosen plan is not available for this quote';

    /** Somente o 400 cuja mensagem informa plano indisponível descarta o plano. */
    public static function isPlanUnavailable(array $result): bool
    {
        if (($result['http_status'] ?? null) !== 400) {
            return false;
        }

        $response = is_array($result['response'] ?? null) ? $result['response'] : [];
        $errors = is_array($response['errors'] ?? null) ? $response['errors'] : [];
        $messages = array_merge(
            array_map(fn ($error) => is_array($error) ? ($error['message'] ?? null) : null, $errors),
            [$response['message'] ?? null, $result['raw_body'] ?? null],
        );

        foreach ($messages as $message) {
            if (is_string($message) && str_contains(mb_strtolower($message), self::PLAN_UNAVAILABLE_MESSAGE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seleciona, no retorno do simulador, a alternativa de 30 parcelas da forma
     * de pagamento esperada. O preço comparado é a primeira parcela.
     *
     * Validação: exatamente 30 valores positivos cuja soma confira com
     * grossAmount; fora disso o preço fica indisponível (null).
     *
     * @return array{amount: string, count: int, payment_type: string, with_entry: bool, first_installment: string, recurring_installment: string|null, details: array}|null
     */
    public function paymentSelection(array $response, string $paymentType): ?array
    {
        foreach (array_is_list($response) ? $response : [$response] as $option) {
            if (! is_array($option) || ($option['paymentType'] ?? null) !== $paymentType) {
                continue;
            }
            foreach (is_array($option['installments'] ?? null) ? $option['installments'] : [] as $plan) {
                if (! is_array($plan) || (int) ($plan['countInstallments'] ?? 0) !== self::INSTALLMENTS) {
                    continue;
                }
                if (($selection = $this->selection($plan, $paymentType)) !== null) {
                    return $selection;
                }
            }
        }

        return null;
    }

    private function selection(array $plan, string $paymentType): ?array
    {
        $values = $plan['installments'] ?? null;
        if (! is_array($values) || ! array_is_list($values) || count($values) !== self::INSTALLMENTS) {
            return null;
        }

        // Centavos inteiros: a soma é conferida sem erro de ponto flutuante.
        $cents = [];
        foreach ($values as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
                return null;
            }
            $cents[] = (int) round((float) $value * 100);
        }

        $gross = $plan['grossAmount'] ?? null;
        if (! is_numeric($gross) || array_sum($cents) !== (int) round((float) $gross * 100)) {
            return null;
        }

        // Valor das parcelas seguintes, somente quando todas forem iguais.
        $recurring = array_unique(array_slice($cents, 1));

        return [
            'amount' => $this->money($cents[0]),
            'count' => self::INSTALLMENTS,
            'payment_type' => $paymentType,
            'with_entry' => false,
            'first_installment' => $this->money($cents[0]),
            'recurring_installment' => count($recurring) === 1 ? $this->money(reset($recurring)) : null,
            'details' => $plan,
        ];
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
