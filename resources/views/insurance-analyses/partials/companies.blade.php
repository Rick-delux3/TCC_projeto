@php
    $analysesByProvider = collect($companies)->keyBy('provider');
    $planNames = ['Basic' => 'Básico', 'Complete' => 'Completo', 'Traditional' => 'Tradicional', 'Tradicional' => 'Tradicional', 'default' => 'Fiança locatícia'];
@endphp
<section class="border-top pt-4 mb-4" aria-labelledby="companies-title">
    <h2 id="companies-title" class="h5 mb-3">Todas as companhias</h2>
    <div class="row g-3">
        @foreach ($providerNames as $provider => $providerName)
            @php
                $blocked = ! in_array($provider, $activeProviders, true);
                $analysis = $blocked ? null : $analysesByProvider->get($provider);
                $attempts = collect($analysis['quote_attempts'] ?? []);
                $plans = $attempts->groupBy('plan_key')->map(function ($attempts) {
                    return $attempts->filter(fn ($item) => $item['status'] === 'approved' && $item['payment_status'] === 'ready' && (float) $item['installment_amount'] > 0)
                        ->sortBy('installment_amount')->first() ?? $attempts->sortBy(fn ($item) => match ($item['status']) {
                            'approved' => 0, 'pending', 'queued', 'processing', 'running', 'manual_review' => 1,
                            'manual_required' => 2, 'failed' => 3, default => 4,
                        })->first();
                });
            @endphp
            <div class="col-12 col-md-6 col-xl-3">
                <article @class(['card h-100', 'bg-body-tertiary' => $blocked]) aria-labelledby="provider-{{ $provider }}" data-provider="{{ $provider }}">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h3 id="provider-{{ $provider }}" class="h6 mb-0">{{ $providerName }}</h3>
                        @if ($blocked)
                            <span class="badge text-bg-secondary">Bloqueada</span>
                        @else
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-analysis-dialog="details-{{ $provider }}" aria-haspopup="dialog" aria-controls="details-{{ $provider }}" @disabled(! $analysis)>Visualizar</button>
                        @endif
                    </div>
                    <div class="card-body d-flex flex-column justify-content-center text-center">
                        @if ($blocked)
                            <p class="text-body-secondary">Aguardando testes e validação da integração.</p>
                            <button type="button" class="btn btn-outline-secondary w-100" disabled>Indisponível</button>
                        @elseif (! $analysis)
                            <p class="mb-0" role="status">Aguardando análise desta companhia.</p>
                        @elseif ($plans->isNotEmpty())
                            <ul class="list-group list-group-flush">
                                @foreach ($plans as $plan)
                                    <li class="list-group-item px-0">
                                        <h4 class="h6">{{ $planNames[$plan['plan_key']] ?? $plan['plan_key'] ?: 'Fiança locatícia' }}</h4>
                                        @include('insurance-analyses.partials.offer-price', ['offerStatus' => $plan['status'], 'amount' => $plan['payment_status'] === 'ready' ? $plan['installment_amount'] : null, 'count' => $plan['installments'], 'manualMessage' => $analysis['manual_message']])
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            @php($price = $analysis['quote']['price'])
                            @include('insurance-analyses.partials.offer-price', ['offerStatus' => $analysis['status'], 'amount' => $price['installment_amount'] ?? null, 'count' => $price['installments'] ?? null, 'manualMessage' => $analysis['manual_message']])
                        @endif
                    </div>
                </article>
            </div>
            @if ($analysis)
                @include('insurance-analyses.partials.company-details')
            @endif
        @endforeach
    </div>
</section>
