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
            @endphp
            @foreach ($detailOffers as $detail)
                <section class="card mb-3">
                    <div class="card-body">
                        <h3 class="h6">{{ $planNames[$detail['plan_key']] ?? $detail['plan_key'] ?: 'Fiança locatícia' }}</h3>
                        @if (array_filter($detail['applicants']))
                            <p class="text-body-secondary">{{ implode(' e ', array_filter($detail['applicants'])) }}</p>
                        @endif
                        @include('insurance-analyses.partials.offer-price', ['offerStatus' => $detail['status'], 'amount' => $detail['payment_status'] === 'ready' ? $detail['installment_amount'] : null, 'count' => $detail['installments'], 'manualMessage' => $analysis['manual_message']])
                        @if ($detail['status'] === 'approved')
                            @php($detailPrice = $detail['quote']['price'])
                            @if ((float) $detail['installment_amount'] > 0 && $detail['payment_status'] === 'ready')
                                <p class="small mt-2">{{ $detail['installments'] }} parcelas de {{ $formatMoney($detail['installment_amount']) }}</p>
                            @endif
                            <dl class="row small mt-3 mb-0">
                                <dt class="col-sm-5">Pagamento</dt><dd class="col-sm-7">{{ ['faturado' => 'Fatura', 'credito' => 'Cartão de crédito', 'Invoice' => 'Fatura', 'Boleto' => 'Boleto', 'CreditCard' => 'Cartão de crédito'][$detail['payment_type']] ?? 'Não informado' }}</dd>
                                <dt class="col-sm-5">Vigência</dt><dd class="col-sm-7">{{ $formatDate($detailPrice['period_start']) }} até {{ $formatDate($detailPrice['period_end']) }}</dd>
                                <dt class="col-sm-5">Custo total do seguro</dt><dd class="col-sm-7">{{ $formatMoney($detailPrice['total'], $detailPrice['currency']) }}</dd>
                            </dl>
                            <h4 class="h6 mt-3">Coberturas e valores</h4>
                            <ul class="list-group list-group-flush">
                                @forelse ($detail['quote']['coverages'] as $coverage)
                                    <li class="list-group-item px-0">
                                        <strong>{{ ['basica' => 'Aluguel', 'condominio' => 'Condom?nio', 'iptu' => 'IPTU', 'gas' => 'G?s', 'agua' => '?gua', 'luz' => 'Energia el?trica', 'danos' => 'Danos ao im?vel', 'pintura' => 'Pintura', 'multa-rescisao' => 'Multa rescis?ria'][$coverage['name']] ?? ($coverage['name'] ?: 'Cobertura') }}</strong>
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
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
        <div class="card-footer text-end"><button type="button" class="btn btn-outline-secondary" data-close-analysis-dialog>Fechar</button></div>
    </div>
</dialog>
