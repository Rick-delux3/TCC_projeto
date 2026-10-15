@php
    $comparisonMessages = [
        'awaiting_batch' => 'As ofertas aparecerão após o início das análises.',
        'awaiting_results' => 'O melhor preço será destacado após todas as companhias finalizarem.',
        'awaiting_consolidation' => 'Aguardando a consolidação do resultado do pacote.',
        'no_approved_quotes' => 'Não há propostas aprovadas neste pacote.',
        'missing_confirmed_total' => 'Há proposta aprovada sem valor total confirmado. A comparação ainda está indisponível.',
        'incomparable_currencies' => 'As propostas não possuem moedas compatíveis para comparação.',
        'incomparable_periods' => 'As propostas não possuem vigências compatíveis para comparação.',
    ];
    $offers = collect($pageData['analyses'])->filter(fn ($analysis) => in_array($analysis['provider'], $activeProviders, true)
        && $analysis['status'] === 'approved' && $analysis['quote']['price']['basis'] === 'gross_total'
        && (float) $analysis['quote']['price']['total'] > 0);
@endphp
<section class="mb-4" aria-labelledby="comparison-title">
    <h2 id="comparison-title" class="h5">Melhores ofertas</h2>
    @if (! $comparison['available'])
        <p class="text-body-secondary" role="status">{{ $comparisonMessages[$comparison['reason']] ?? 'Comparação indisponível.' }}</p>
    @endif
    <div class="row g-3">
        @forelse ($offers as $offer)
            @php
                $isBest = $comparison['available'] && $comparison['best_quote']['analysis_id'] === $offer['id'];
                $price = $offer['quote']['price'];
            @endphp
            <div class="col-md-6 col-lg-4">
                <article @class(['card h-100', 'border-primary' => $isBest])>
                    @if ($isBest)
                        <div class="card-header text-bg-primary">Melhor preço</div>
                    @endif
                    <div class="card-body">
                        <h3 class="h6">{{ $providerNames[$offer['provider']] }}</h3>
                        <p class="fs-4 fw-semibold mb-1">{{ $formatMoney($price['total'], $price['currency']) }}</p>
                        <p class="small text-body-secondary mb-1">Prêmio bruto total da vigência</p>
                        <p class="small mb-0">{{ $formatDate($price['period_start']) }} até {{ $formatDate($price['period_end']) }}</p>
                    </div>
                </article>
            </div>
        @empty
            <div class="col-12"><p class="text-body-secondary mb-0">Nenhuma oferta aprovada com preço confirmado disponível.</p></div>
        @endforelse
    </div>
</section>
