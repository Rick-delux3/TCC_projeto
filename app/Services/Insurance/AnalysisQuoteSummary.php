<?php

namespace App\Services\Insurance;

use App\Models\InsuranceExecution;
use Illuminate\Support\Arr;

class AnalysisQuoteSummary
{
    /** Domínio documentado das ofertas Porto (PUT/GET do orçamento). */
    private const PORTO_OFFERS = ['0001' => 'Essencial 12', '0005' => 'Essencial 30', '0007' => 'Essencial 20', '0008' => 'Fiança'];

    /** Coberturas Porto pelo código enviado no POST; o nome da API vem com caixa irregular. */
    private const PORTO_COVERAGES = [
        '001' => 'Aluguel', '002' => 'IPTU', '003' => 'Condomínio', '004' => 'Água', '005' => 'Energia elétrica',
        '006' => 'Gás', '007' => 'Danos ao imóvel', '008' => 'Pintura interna', '009' => 'Pintura externa',
        '010' => 'Multa rescisória',
    ];

    /** @return array<string, mixed> */
    public function summarize(InsuranceExecution $analysis): array
    {
        if ($analysis->provider === 'tokio') {
            return $this->tokio($analysis);
        }
        if ($analysis->provider === 'porto') {
            return $this->porto($analysis);
        }
        $payload = $analysis->providerResponsePayload();
        $response = $payload['response'] ?? $payload;
        $response = is_array($response) ? $response : [];
        $too = $analysis->isTooProvider();

        if ($too) {
            $quote = data_get($payload, 'quote_latest.response', []);
            $quote = is_array($quote) ? ($quote['response'] ?? $quote) : [];
            $quote = is_array($quote) ? $quote : [];
            $conditions = $quote['condicoesPagamento']
                ?? data_get($payload, 'quote_summary.paymentConditions')
                ?? $analysis->available_plans ?? [];
            $gross = is_array($conditions) ? ($conditions['premioBrutoTotal'] ?? null) : null;
            $coverages = $quote['coberturas'] ?? data_get($payload, 'quote_summary.coverages')
                ?? $analysis->available_assistances ?? [];
            $start = $quote['inicioVigencia'] ?? $analysis->lease_start_date?->format('Y-m-d');
            $end = $quote['fimVigencia'] ?? $analysis->lease_end_date?->format('Y-m-d');
        } else {
            $quote = $response['quote'] ?? $response['data'] ?? $response;
            $quote = is_array($quote) ? $quote : [];
            $gross = $quote['grossPremium'] ?? $analysis->gross_premium;
            $coverages = $quote['coverages'] ?? collect($quote['riskObjects'] ?? [])->flatMap(
                fn ($risk): array => is_array($risk) && is_array($risk['coverages'] ?? null) ? $risk['coverages'] : []
            )->all();
            $start = $quote['policyPeriodStart'] ?? $analysis->lease_start_date?->format('Y-m-d');
            $end = $quote['policyPeriodEnd'] ?? $analysis->lease_end_date?->format('Y-m-d');
        }

        $total = $this->money($gross);
        $selection = $payload['quote_selection'] ?? null;

        return [
            'analysis_id' => $analysis->id,
            'provider' => $analysis->provider,
            'status' => $analysis->status,
            'quote_id' => $analysis->quote_id,
            'quote_number' => $analysis->quote_number,
            'proposal_id' => $analysis->proposal_id,
            'price' => [
                'total' => $analysis->isApprovedResult() ? ($total ?? $this->money($analysis->premium_amount)) : null,
                'basis' => $total !== null ? 'gross_total' : 'unconfirmed',
                'currency' => strtoupper(trim((string) ($quote['currency'] ?? 'BRL'))),
                'period_start' => $this->date($start),
                'period_end' => $this->date($end),
                'comparison_basis' => is_array($selection) ? 'installment' : 'gross_total',
                'installment_amount' => is_array($selection) ? $this->money($selection['installment_amount'] ?? null) : null,
                'installments' => $selection['installments'] ?? null,
                'payment_type' => $selection['payment_type'] ?? null,
            ],
            'coverages' => $analysis->isApprovedResult() ? collect($coverages)->filter(fn ($coverage): bool => is_array($coverage))
                ->map(fn (array $coverage): array => [
                    'name' => $coverage['tipo'] ?? $coverage['name'] ?? $coverage['key'] ?? null,
                    'insured_amount' => $this->money($coverage['insuredAmount'] ?? null),
                    'declared_amount' => $this->money($coverage['verba'] ?? null),
                    'premium' => $this->money($coverage['premioLiquido'] ?? $coverage['premiumAmount'] ?? null),
                    'premium_basis' => $too ? 'net' : 'reported',
                    'indemnity_period' => $coverage['periodoIndenitario'] ?? null,
                    'contracted' => isset($coverage['contratada'])
                        ? filter_var($coverage['contratada'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
                    'details' => Arr::only($coverage, [
                        'key', 'name', 'tipo', 'insuredAmount', 'verba', 'premiumAmount', 'premioLiquido',
                        'grossPremium', 'commercialPremium', 'iof', 'deductible', 'periodoIndenitario', 'contratada',
                    ]),
                ])->values()->all() : [],
        ];
    }

    private function tokio(InsuranceExecution $analysis): array
    {
        $payload = $analysis->providerResponsePayload();
        $selection = data_get($payload, 'payment.selection', []);
        $selection = is_array($selection) ? $selection : [];
        $approved = $analysis->isApprovedResult();
        $coverages = $selection['coverages'] ?? [];

        return [
            'analysis_id' => $analysis->id, 'provider' => 'tokio', 'status' => $analysis->status,
            'quote_id' => $analysis->quote_id, 'quote_number' => $analysis->quote_number, 'proposal_id' => null,
            'price' => [
                'total' => $approved ? $this->money(data_get($selection, 'details.vlPremTot')) : null,
                'basis' => 'reported_total', 'currency' => 'BRL', 'comparison_basis' => 'installment',
                'period_start' => $analysis->lease_start_date?->format('Y-m-d'),
                'period_end' => $analysis->lease_end_date?->format('Y-m-d'),
                'installment_amount' => $approved ? $this->money(data_get($payload, 'quote_selection.installment_amount')) : null,
                'installments' => data_get($payload, 'quote_selection.installments'),
                'payment_type' => data_get($payload, 'quote_selection.payment_type'),
            ],
            'assistance' => $selection['assistance'] ?? null,
            'payment_issue' => data_get($payload, 'payment.unavailable_reason'),
            'document_available' => false,
            'coverages' => $approved ? collect($coverages)->filter(fn ($item) => is_array($item))->map(fn ($item) => [
                'name' => $item['descricao'] ?? null, 'insured_amount' => $this->money($item['valorIS'] ?? null),
                'declared_amount' => $this->money($item['valorVerba'] ?? null),
                'premium' => $this->money($item['valorPremio'] ?? null), 'premium_basis' => 'reported',
                'indemnity_period' => null, 'contracted' => null,
                'details' => Arr::only($item, ['codigo', 'caracteristica', 'textoFranquia', 'textoFranquiaComplementar']),
            ])->values()->all() : [],
        ];
    }

    /**
     * Porto: preços, totais e coberturas vêm da consulta de preços gravada em
     * payment (GET de ofertas ou do orçamento atualizado).
     *
     * - "card": a Fiança e o Essencial mais barato, exibidos lado a lado no card;
     * - "best_offer": o plano mais barato entre eles, que é o preço da Porto na
     *   comparação entre companhias (installment_amount de quote_selection) e
     *   cujos totais e coberturas resumem a análise;
     * - "offers": todos os planos (Fiança e Essenciais) para os detalhes.
     *
     * Registros anteriores a 11/11/2026 guardavam a Fiança em "selection" e não
     * tinham "fianca"/"essential": esses valores são derivados aqui.
     */
    private function porto(InsuranceExecution $analysis): array
    {
        $payload = $analysis->providerResponsePayload();
        $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $approved = $analysis->isApprovedResult();
        $comparison = (string) config('services.porto.comparison_offer', '0008');
        $selection = is_array($payment['selection'] ?? null) ? $payment['selection'] : null;
        $fianca = array_key_exists('fianca', $payment)
            ? (is_array($payment['fianca']) ? $payment['fianca'] : null)
            : (($selection['offer_code'] ?? null) === $comparison ? $selection : null);
        $alternatives = collect($payment['alternatives'] ?? [])
            ->filter(fn ($item): bool => is_array($item) && is_string($item['offer_code'] ?? null))
            ->sortBy(fn (array $item): float => (float) ($item['amount'] ?? 0))->values();
        $essential = is_array($payment['essential'] ?? null) ? $payment['essential'] : $alternatives->first();
        $quoteSelection = is_array($payload['quote_selection'] ?? null) ? $payload['quote_selection'] : null;
        $catalog = collect(data_get($payment, 'response.ofertas'))
            ->filter(fn ($offer): bool => is_array($offer) && is_string($offer['seguro'] ?? null))->keyBy('seguro');

        $offers = [];
        if ($approved) {
            if ($fianca !== null || $catalog->has($comparison)) {
                $offers[] = $this->portoOffer($comparison, $catalog->get($comparison, []), $fianca, 'comparison');
            }
            foreach ($alternatives as $item) {
                $offers[] = $this->portoOffer($item['offer_code'], $catalog->get($item['offer_code'], []), $item, 'alternative');
            }
        }
        $find = fn (?array $item): ?array => $item === null ? null
            : collect($offers)->first(fn (array $offer): bool => $offer['code'] === ($item['offer_code'] ?? null) && $offer['installment_amount'] !== null);
        $bestOffer = $find($selection);
        $cheapestEssential = $find($essential);
        $offers = array_map(fn (array $offer): array => $offer + [
            'cheapest_essential' => $offer['code'] === ($cheapestEssential['code'] ?? null),
            'best' => $offer['code'] === ($bestOffer['code'] ?? null),
        ], $offers);
        $main = $bestOffer ?? collect($offers)->firstWhere('role', 'comparison');
        $total = $main['price']['total'] ?? null;

        return [
            'analysis_id' => $analysis->id, 'provider' => 'porto', 'status' => $analysis->status,
            'quote_id' => $analysis->quote_id, 'quote_number' => $analysis->quote_number, 'proposal_id' => $analysis->proposal_id,
            'price' => [
                'total' => $total,
                'basis' => $total !== null ? 'gross_total' : 'unconfirmed',
                'currency' => 'BRL',
                'period_start' => $analysis->lease_start_date?->format('Y-m-d'),
                'period_end' => $analysis->lease_end_date?->format('Y-m-d'),
                'comparison_basis' => $quoteSelection !== null ? 'installment' : 'gross_total',
                'installment_amount' => $quoteSelection !== null ? $this->money($quoteSelection['installment_amount'] ?? null) : null,
                'installments' => $quoteSelection['installments'] ?? null,
                'payment_type' => $quoteSelection['payment_type'] ?? null,
            ] + Arr::except($main['price'] ?? [], ['total']),
            'payment_issue' => $payment['unavailable_reason'] ?? null,
            'fianca_issue' => $approved && $fianca === null
                ? ($payment['fianca_unavailable_reason'] ?? $payment['unavailable_reason'] ?? null) : null,
            'best_offer' => $bestOffer === null ? null : Arr::only($bestOffer, ['code', 'name', 'role', 'installment_amount', 'installments']),
            'card' => [
                'fianca' => collect($offers)->firstWhere('role', 'comparison'),
                'essential' => $cheapestEssential === null ? null : collect($offers)->firstWhere('code', $cheapestEssential['code']),
            ],
            'coverages' => $main['coverages'] ?? [],
            'offers' => $offers,
        ];
    }

    /**
     * Uma oferta Porto pronta para exibição. Sem condição selecionada (Fiança
     * sem comissão confirmada) não há preço nem custo de cobertura: os valores
     * da consulta não são apresentados como finais.
     *
     * @param  array<string, mixed>  $offer  oferta da consulta de preços
     * @param  array<string, mixed>|null  $priced  condição selecionada (selection ou alternativa)
     */
    private function portoOffer(string $code, array $offer, ?array $priced, string $role): array
    {
        $condition = is_array($priced['details'] ?? null) ? $priced['details'] : [];
        $isPriced = $priced !== null;

        return [
            'code' => $code,
            'name' => self::PORTO_OFFERS[$code] ?? (is_string($offer['nomeSeguro'] ?? null) ? trim($offer['nomeSeguro']) : 'Oferta '.$code),
            'role' => $role,
            'installment_amount' => $isPriced ? $this->money($priced['amount'] ?? null) : null,
            'installments' => $isPriced ? ($priced['count'] ?? null) : null,
            'entry_options' => $this->portoEntryOptions($offer, $priced),
            'price' => [
                'total' => $isPriced ? $this->money($condition['premioTotal'] ?? null) : null,
                'net' => $isPriced ? $this->money($condition['premioLiquido'] ?? null) : null,
                'iof' => $isPriced ? $this->money($condition['iof'] ?? null) : null,
                'first_due' => $isPriced ? $this->brazilianDate($condition['primeiroVencimento'] ?? null) : null,
                'guarantee_limit' => $this->money(data_get($offer, 'calculo.valorLMG')),
                'payment_label' => $isPriced && is_string($priced['payment_method_name'] ?? null) ? trim($priced['payment_method_name']) : null,
            ],
            'coverages' => collect($offer['coberturas'] ?? [])->filter(fn ($item): bool => is_array($item))
                ->map(function (array $item) use ($isPriced): array {
                    $code = $item['codCobertura'] ?? null;
                    $code = is_int($code) ? str_pad((string) $code, 3, '0', STR_PAD_LEFT) : (string) $code;

                    return [
                        'name' => self::PORTO_COVERAGES[$code] ?? (is_string($item['nomeCobertura'] ?? null) ? trim($item['nomeCobertura']) : null),
                        'insured_amount' => $this->money($item['valorLMI'] ?? null),
                        'declared_amount' => $this->money($item['valorIS'] ?? null),
                        'premium' => $isPriced ? $this->money($item['valorPremio'] ?? null) : null,
                        'premium_basis' => 'net', 'indemnity_period' => null, 'contracted' => null, 'details' => [],
                    ];
                })->values()->all(),
            'benefits' => collect($offer['beneficios'] ?? [])
                ->map(fn ($item): mixed => is_array($item) ? ($item['titulo'] ?? $item['descricao'] ?? null) : null)
                ->filter(fn ($title): bool => is_string($title) && trim($title) !== '')
                ->map(fn (string $title): string => trim($title))->unique()->values()->all(),
        ];
    }

    /**
     * Condições de fatura apenas para exibição, sem alterar a seleção ou o PDF.
     *
     * @return array<string, array{amount: string, count: int}|null>
     */
    private function portoEntryOptions(array $offer, ?array $priced): array
    {
        if ($priced === null || ($priced['payment_method'] ?? $priced['payment_type'] ?? null) !== 'FAT') {
            return [];
        }

        $options = [
            'without_entry' => ['amount' => $priced['amount'], 'count' => $priced['count']],
            'with_entry' => null,
        ];
        foreach (app(PortoQuoteResponse::class)->alternatives(['ofertas' => [$offer]]) as $alternative) {
            if ($alternative['payment_method'] === 'BFT' && $alternative['condition_code'] === '229'
                && $alternative['count'] === PortoQuoteResponse::installmentsFor('FAT')) {
                $options['with_entry'] = ['amount' => $alternative['amount'], 'count' => $alternative['count']];
                break;
            }
        }

        return $options;
    }

    /** Data da Porto em DD/MM/AAAA para Y-m-d; inválida vira null. */
    private function brazilianDate(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])) {
            return null;
        }

        return "{$parts[3]}-{$parts[2]}-{$parts[1]}";
    }

    private function money(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim(str_replace(['R$', ' '], '', $value));
            if (str_contains($value, ',')) {
                $value = str_replace(',', '.', str_replace('.', '', $value));
            }
        }

        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0
            ? number_format((float) $value, 2, '.', '') : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|T| )/', $value, $parts)) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? substr($value, 0, 10) : null;
    }
}
