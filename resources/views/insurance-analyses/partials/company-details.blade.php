<dialog id="details-{{ $provider }}" class="border-0 rounded p-0 w-100" style="max-width: 800px; max-height: 85vh" aria-labelledby="details-title-{{ $provider }}">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-3">
            <h2 id="details-title-{{ $provider }}" class="h5 mb-0">Detalhes da cotação · {{ $providerName }}</h2>
            <button type="button" class="btn-close" data-close-analysis-dialog aria-label="Fechar detalhes"></button>
        </div>
        <div class="card-body">
            @php
                $detailOffers = $attempts->isNotEmpty() ? $attempts : collect([[
                    'plan_key' => 'default', 'status' => $analysis['status'], 'applicants' => [],
                    'quote' => $analysis['quote'], 'installment_amount' => $analysis['quote']['price']['installment_amount'] ?? null,
                    'installments' => $analysis['quote']['price']['installments'] ?? null,
                    'payment_type' => $analysis['quote']['price']['payment_type'] ?? null,
                    'payment_status' => 'ready',
                ]]);
                // Porto: cada orçamento aprovado traz a Fiança (preço do card e da
                // comparação) e os Essenciais (alternativas), cada um com preço,
                // totais e coberturas próprios. Cada oferta vira uma seção.
                if ($provider === 'porto') {
                    $detailOffers = $detailOffers->flatMap(fn ($detail) => $detail['status'] === 'approved' && ! empty($detail['quote']['offers'])
                        ? collect($detail['quote']['offers'])->map(fn ($offer) => array_merge($detail, [
                            'title' => $offer['name'],
                            'offer_role' => $offer['role'],
                            'installment_amount' => $offer['installment_amount'],
                            'installments' => $offer['installments'] ?? $detail['installments'],
                            'payment_status' => $offer['installment_amount'] !== null ? 'ready' : $detail['payment_status'],
                            'benefits' => $offer['benefits'],
                            'quote' => array_merge($detail['quote'], [
                                'price' => array_merge($detail['quote']['price'], $offer['price']),
                                'coverages' => $offer['coverages'],
                            ]),
                        ]))->all()
                        : [$detail])->values();
                }
                $paymentNames = [
                    'faturado' => 'Fatura', 'credito' => 'Cartão de crédito', 'Invoice' => 'Fatura', 'Boleto' => 'Boleto',
                    'CreditCard' => 'Cartão de crédito', 'FAT' => 'Fatura', 'CC' => 'Cartão de crédito',
                ];
            @endphp
            @foreach ($detailOffers as $detail)
                <section class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <h3 class="h6 mb-0">{{ $detail['title'] ?? ($planNames[$detail['plan_key']] ?? $detail['plan_key'] ?: 'Fiança locatícia') }}</h3>
                            @if ((($cardPlan['id'] ?? null) !== null && ($detail['id'] ?? null) === $cardPlan['id'] && $detailOffers->count() > 1)
                                || (($detail['offer_role'] ?? null) === 'comparison' && ($detail['selected'] ?? true) && (float) $detail['installment_amount'] > 0 && $detailOffers->count() > 1))
                                <span class="badge text-bg-success">Exibido no card</span>
                            @elseif (($detail['offer_role'] ?? null) === 'alternative')
                                <span class="badge text-bg-secondary">Alternativa</span>
                            @endif
                        </div>
                        @if (array_filter($detail['applicants']))
                            <p class="text-body-secondary">{{ implode(' e ', array_filter($detail['applicants'])) }}</p>
                        @endif
                        @include('insurance-analyses.partials.offer-price', ['offerStatus' => $detail['status'], 'amount' => $detail['payment_status'] === 'ready' ? $detail['installment_amount'] : null, 'count' => $detail['installments'], 'recurring' => $detail['recurring_installment'] ?? null, 'manualMessage' => $analysis['manual_message'], 'displayState' => $detail['display_state'] ?? $analysis['display_state']])
                        @if ($detail['status'] === 'approved')
                            @php($detailPrice = $detail['quote']['price'])
                            @if ((float) $detail['installment_amount'] > 0 && $detail['payment_status'] === 'ready')
                                @php($detailRecurring = $detail['recurring_installment'] ?? null)
                                <p class="small mt-2 mb-0">
                                    @if ($detailRecurring !== null && (int) $detail['installments'] > 1 && round((float) $detailRecurring, 2) !== round((float) $detail['installment_amount'], 2))
                                        1ª parcela de {{ $formatMoney($detail['installment_amount']) }} e {{ (int) $detail['installments'] - 1 }} parcelas de {{ $formatMoney($detailRecurring) }}
                                    @else
                                        {{ $detail['installments'] }} parcelas de {{ $formatMoney($detail['installment_amount']) }}
                                    @endif
                                </p>
                            @endif
                            <dl class="row small mt-3 mb-0">
                                <dt class="col-sm-5">Pagamento</dt><dd class="col-sm-7">{{ ($detailPrice['payment_label'] ?? null) ?: ($paymentNames[$detail['payment_type']] ?? 'Não informado') }}</dd>
                                @if (! empty($detailPrice['first_due']))
                                    <dt class="col-sm-5">Primeiro vencimento</dt><dd class="col-sm-7">{{ $formatDate($detailPrice['first_due']) }}</dd>
                                @endif
                                <dt class="col-sm-5">Vigência</dt><dd class="col-sm-7">{{ $formatDate($detailPrice['period_start']) }} até {{ $formatDate($detailPrice['period_end']) }}</dd>
                                <dt class="col-sm-5">Custo total do seguro</dt><dd class="col-sm-7">{{ $formatMoney($detailPrice['total'], $detailPrice['currency']) }}</dd>
                                {{-- Composição e limite informados pela Porto; ausentes nas demais companhias. --}}
                                @foreach (['net' => 'Prêmio líquido', 'iof' => 'IOF', 'guarantee_limit' => 'Limite máximo de garantia'] as $field => $label)
                                    @if (($detailPrice[$field] ?? null) !== null)
                                        <dt class="col-sm-5">{{ $label }}</dt><dd class="col-sm-7">{{ $formatMoney($detailPrice[$field], $detailPrice['currency']) }}</dd>
                                    @endif
                                @endforeach
                            </dl>
                            <h4 class="h6 mt-3">Coberturas e valores</h4>
                            <ul class="list-group list-group-flush">
                                @forelse ($detail['quote']['coverages'] as $coverage)
                                    <li class="list-group-item px-0">
                                        <strong>{{ ['basica' => 'Aluguel', 'condominio' => 'Condomínio', 'iptu' => 'IPTU', 'gas' => 'Gás', 'agua' => 'Água', 'luz' => 'Energia elétrica', 'danos' => 'Danos ao imóvel', 'pintura' => 'Pintura', 'multa-rescisao' => 'Multa rescisória'][$coverage['name']] ?? ($coverage['name'] ?: 'Cobertura') }}</strong>
                                        @if ($coverage['contracted'] !== null)
                                            <span class="small d-block">{{ $coverage['contracted'] ? 'Contratada' : 'Não contratada' }}</span>
                                        @endif
                                        @foreach (['insured_amount' => 'Limite de cobertura', 'declared_amount' => 'Valor declarado', 'premium' => 'Custo da cobertura'] as $field => $label)
                                            @if ($coverage[$field] !== null)
                                                <span class="d-block small">{{ $label }}: {{ $formatMoney($coverage[$field], $detailPrice['currency']) }}</span>
                                            @endif
                                        @endforeach
                                        @foreach (['grossPremium' => 'Custo bruto', 'commercialPremium' => 'Custo comercial', 'iof' => 'IOF', 'deductible' => 'Franquia'] as $field => $label)
                                            @if (is_numeric($coverage['details'][$field] ?? null))
                                                <span class="d-block small">{{ $label }}: {{ $formatMoney($coverage['details'][$field], $detailPrice['currency']) }}</span>
                                            @endif
                                        @endforeach
                                        @foreach (['textoFranquia', 'textoFranquiaComplementar'] as $field)
                                            @if (is_string($coverage['details'][$field] ?? null) && filled($coverage['details'][$field]))
                                                <p class="small mb-0">{{ $coverage['details'][$field] }}</p>
                                            @endif
                                        @endforeach
                                        @if ($coverage['indemnity_period'] !== null)
                                            <span class="d-block small">Período de indenização: {{ $coverage['indemnity_period'] }} meses</span>
                                        @endif
                                    </li>
                                @empty
                                    <li class="list-group-item px-0 text-body-secondary">A seguradora não informou os valores das coberturas.</li>
                                @endforelse
                            </ul>
                            @if (! empty($detail['benefits']))
                                <h4 class="h6 mt-3">Benefícios</h4>
                                <ul class="small mb-0">
                                    @foreach ($detail['benefits'] as $benefit)
                                        <li>{{ $benefit }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
        <div class="card-footer text-end"><button type="button" class="btn btn-outline-secondary" data-close-analysis-dialog>Fechar</button></div>
    </div>
</dialog>
