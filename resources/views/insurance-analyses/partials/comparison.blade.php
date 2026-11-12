@php
    $comparisonMessages = [
        'awaiting_batch' => 'As ofertas aparecerão após o início das análises.',
        'awaiting_results' => 'O melhor preço será destacado após todas as companhias finalizarem.',
        'awaiting_consolidation' => 'Aguardando a consolidação do resultado do pacote.',
        'no_approved_quotes' => 'Não há propostas aprovadas neste pacote.',
        'missing_confirmed_total' => 'Há proposta aprovada sem valor total confirmado. A comparação ainda está indisponível.',
        'missing_confirmed_installment' => 'Há proposta aprovada sem parcela confirmada. A comparação ainda está indisponível.',
        'incomparable_currencies' => 'As propostas não possuem moedas compatíveis para comparação.',
        'incomparable_periods' => 'As propostas não possuem vigências compatíveis para comparação.',
    ];
    // Resultados do pacote atual, inclusive de companhia desligada depois da análise.
    $offers = collect($pageData['analyses'])->filter(fn ($analysis) => $analysis['status'] === 'approved'
        && (($analysis['quote']['price']['comparison_basis'] ?? null) === 'installment'
            ? (float) ($analysis['quote']['price']['installment_amount'] ?? 0) > 0
            : ($analysis['quote']['price']['basis'] === 'gross_total' && (float) $analysis['quote']['price']['total'] > 0)));
    $offers = $offers->sortBy(fn ($offer) => (float) ($offer['quote']['price']['installment_amount'] ?? $offer['quote']['price']['total']))->take(3)->values();
    if ($comparison['available'] && $offers->count() > 1) {
        $best = $offers->firstWhere('id', $comparison['best_quote']['analysis_id']);
        if ($best) {
            $offers = $offers->reject(fn ($offer) => $offer['id'] === $best['id'])->values();
            $offers->splice(1, 0, [$best]);
        }
    }
@endphp
<section class="mb-4" aria-labelledby="comparison-title" data-analysis-component="comparison">
    <h2 id="comparison-title" class="h5">Melhores ofertas</h2>
    @if (! $comparison['available'])
        <p class="text-body-secondary" role="status">{{ $comparisonMessages[$comparison['reason']] ?? 'Comparação indisponível.' }}</p>
    @endif
    <div class="row g-3 justify-content-center align-items-center">
        @forelse ($offers as $offer)
            @php
                $isBest = $comparison['available'] && $comparison['best_quote']['analysis_id'] === $offer['id'];
                $price = $offer['quote']['price'];
            @endphp
            <div @class(['col-12 col-md-6 col-lg-4', 'order-first order-lg-0' => $isBest])>
                <article @class(['card h-100', 'border-primary shadow-sm' => $isBest])>
                    @if ($isBest)
                        <div class="card-header text-bg-primary">Melhor preço</div>
                    @endif
                    <div class="card-body text-center">
                        <h3 @class(['h6', 'mb-1' => filled($offer['quote']['best_offer']['name'] ?? null)])>{{ $providerNames[$offer['provider']] }}</h3>
                        {{-- Porto: plano mais barato entre a Fiança e o Essencial, usado na comparação. --}}
                        @if (filled($offer['quote']['best_offer']['name'] ?? null))
                            <p class="small text-body-secondary mb-2" data-comparison-plan>{{ $offer['quote']['best_offer']['name'] }}</p>
                        @endif
                        @if (($price['comparison_basis'] ?? null) === 'installment')
                            <p @class(['fw-semibold mb-1', 'display-6 text-primary' => $isBest, 'fs-4 text-body-secondary' => ! $isBest])>{{ $formatMoney($price['installment_amount'], $price['currency']) }}</p>
                            <p class="small text-body-secondary mb-1">Por parcela · {{ $price['installments'] }} parcelas</p>
                        @else
                            <p @class(['fw-semibold mb-1', 'display-6 text-primary' => $isBest, 'fs-4 text-body-secondary' => ! $isBest])>{{ $formatMoney($price['total'], $price['currency']) }}</p>
                            <p class="small text-body-secondary mb-1">Prêmio bruto total da vigência</p>
                        @endif
                    </div>
                </article>
            </div>
        @empty
            <div class="col-12"><p class="text-body-secondary mb-0">Nenhuma oferta aprovada com preço confirmado disponível.</p></div>
        @endforelse
        @if ($comparison['available'] && $offers->count() === 2)
            <div class="d-none d-lg-block col-lg-4" aria-hidden="true"></div>
        @endif
    </div>
</section>
