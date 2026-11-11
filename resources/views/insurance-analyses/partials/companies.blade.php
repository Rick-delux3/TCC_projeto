@php
    $analysesByProvider = collect($companies)->keyBy('provider');
    $canEditExpenses = ($pageData['permissions']['edit_lead'] ?? false) && ($pageData['permissions']['create_analysis'] ?? false);
    $planNames = ['Basic' => 'Básico', 'Complete' => 'Completo', 'Traditional' => 'Tradicional', 'Tradicional' => 'Tradicional', 'default' => 'Fiança locatícia'];
@endphp
<section class="border-top pt-4 mb-4" aria-labelledby="companies-title">
    <h2 id="companies-title" class="h5 mb-3">Todas as companhias</h2>
    <div class="row g-3">
        @foreach ($providerNames as $provider => $providerName)
            @php
                $analysis = $analysesByProvider->get($provider);
                // Desligada no .env: sem análise no pacote fica bloqueada; com análise
                // anterior, mostra o resultado somente para leitura.
                $readOnly = $analysis !== null && ! in_array($provider, $activeProviders, true);
                $blocked = $analysis === null && ! in_array($provider, $activeProviders, true);
                // Ligada depois que o pacote atual foi criado: entra na próxima análise.
                $notIncluded = ! $blocked && $analysis === null && ! ($pageData['awaiting_batch'] ?? true);
                // Porto e Tokio aceitam reanálise; Pottencial e Too não. Sem análise no
                // pacote, a mesma regra vem direto do resolver.
                $reanalysisSupported = (bool) ($analysis['reanalysis_supported']
                    ?? \App\Services\Insurance\Providers\InsuranceProviderResolver::supportsReanalysis($provider));
                $attempts = collect($analysis['quote_attempts'] ?? []);
                $plans = $attempts->groupBy('plan_key')->map(function ($attempts) {
                    return $attempts->filter(fn ($item) => $item['status'] === 'approved' && $item['payment_status'] === 'ready' && (float) $item['installment_amount'] > 0)
                        ->sortBy('installment_amount')->first() ?? $attempts->sortBy(fn ($item) => match ($item['status']) {
                            'approved' => 0, 'pending', 'queued', 'processing', 'running', 'manual_review' => 1,
                            'manual_required' => 2, 'failed' => 3, default => 4,
                        })->first();
                });
                $cardPlan = null;
                if ($provider === 'pottencial') {
                    // Plano indisponível (400) é descartado do card e do Visualizar.
                    $priced = fn ($item) => $item['status'] === 'approved' && $item['payment_status'] === 'ready' && (float) $item['installment_amount'] > 0;
                    $attempts = $attempts->reject(fn ($item) => $item['status'] === \App\Services\Insurance\PottencialQuoteResponse::PLAN_UNAVAILABLE)
                        ->sortBy(fn ($item) => $priced($item) ? (float) $item['installment_amount'] : PHP_FLOAT_MAX)->values();
                    // O card mostra somente o plano mais barato (o selecionado na rodada);
                    // os demais ficam no Visualizar. Sem aprovação, vale o estado da companhia.
                    $cardPlan = $attempts->first(fn ($item) => $item['selected'] && $priced($item))
                        ?? $attempts->first($priced)
                        ?? $attempts->firstWhere('status', 'approved');
                    $plans = collect($cardPlan ? [$cardPlan] : []);
                }
            @endphp
            <div class="col-12 col-md-6 col-xl-3" data-analysis-component="provider-{{ $provider }}">
                <article @class(['card h-100', 'bg-body-tertiary' => $blocked, 'border-success border-2' => ! $blocked && $reanalysisSupported]) data-provider-read-only="{{ $readOnly ? 'true' : 'false' }}" aria-labelledby="provider-{{ $provider }}" data-provider="{{ $provider }}" data-analysis-state="{{ $blocked ? 'blocked' : ($notIncluded ? 'not_included' : ($analysis['display_state']['state'] ?? 'processing')) }}" aria-busy="{{ ! $blocked && ! $notIncluded && ($analysis['display_state']['processing'] ?? true) ? 'true' : 'false' }}">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h3 id="provider-{{ $provider }}" class="h6 mb-0">{{ $providerName }}</h3>
                        @if ($blocked)
                            <span class="badge text-bg-secondary">Bloqueada</span>
                        @else
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-analysis-dialog="details-{{ $provider }}" aria-haspopup="dialog" aria-controls="details-{{ $provider }}" @disabled(! $analysis)>Visualizar</button>
                            @if ($readOnly)
                                <span class="badge text-bg-secondary">Desativada</span>
                            @elseif ($canEditExpenses && $reanalysisSupported)
                                @php($providerReanalysis = $analysis['actions']['expense_reanalysis'] ?? ['available' => false, 'url' => null])
                                <button type="button" class="btn btn-sm btn-primary"
                                    data-provider-reanalysis="{{ $provider }}" data-provider-name="{{ $providerName }}" data-url="{{ $providerReanalysis['url'] }}"
                                    @disabled(! $providerReanalysis['available'])
                                    @unless ($providerReanalysis['available']) title="Disponível quando esta companhia concluir a análise atual." @endunless>
                                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true" data-loading-indicator></span>
                                    <span data-button-label>Reanalisar</span>
                                </button>
                            @endif
                        @endif
                    </div>
                    <div class="card-body d-flex flex-column justify-content-center text-center">
                        @if ($blocked)
                            <p class="text-body-secondary">Aguardando testes e validação da integração.</p>
                            <button type="button" class="btn btn-outline-secondary w-100" disabled>Indisponível</button>
                        @elseif ($notIncluded)
                            <p class="text-body-secondary mb-0">Esta companhia não participou deste pacote de análises.</p>
                        @elseif (! $analysis)
                            <p class="mb-0" role="status"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Aguardando análise desta companhia.</p>
                        @elseif ($plans->isNotEmpty())
                            <ul class="list-group list-group-flush">
                                @foreach ($plans as $plan)
                                    <li class="list-group-item px-0">
                                        <h4 class="h6">{{ $planNames[$plan['plan_key']] ?? $plan['plan_key'] ?: 'Fiança locatícia' }}</h4>
                                        @include('insurance-analyses.partials.offer-price', ['offerStatus' => $plan['status'], 'amount' => $plan['payment_status'] === 'ready' ? $plan['installment_amount'] : null, 'count' => $plan['installments'], 'recurring' => $plan['recurring_installment'] ?? null, 'manualMessage' => $analysis['manual_message'], 'displayState' => $plan['display_state']])
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            @php($price = $analysis['quote']['price'])
                            @include('insurance-analyses.partials.offer-price', ['offerStatus' => $analysis['status'], 'amount' => $price['installment_amount'] ?? null, 'count' => $price['installments'] ?? null, 'manualMessage' => $analysis['manual_message'], 'displayState' => $analysis['display_state']])
                        @endif
                    </div>
                    @if ($blocked)
                        {{-- Companhia desligada no .env: sem aviso de reanálise. --}}
                    @elseif ($reanalysisSupported)
                        <div class="card-footer bg-success-subtle border-success text-success-emphasis text-center small fw-semibold py-3" data-reanalysis-notice="supported">
                            Esta companhia permite reanálise.
                        </div>
                    @else
                        {{-- Faixa escura com o botão desfocado ao fundo; preço e resultado seguem nítidos. --}}
                        <div class="card-footer position-relative overflow-hidden bg-dark border-dark text-center py-3" data-reanalysis-notice="unsupported">
                            <span class="btn btn-sm btn-light w-100 pe-none user-select-none" style="filter: blur(3px); opacity: .35" aria-hidden="true">Reanalisar</span>
                            <p class="position-absolute top-50 start-50 translate-middle w-100 px-3 mb-0 small fw-semibold text-white">Esta companhia não permite reanálise.</p>
                        </div>
                    @endif
                </article>
            </div>
            <div data-analysis-component="details-{{ $provider }}" style="display: contents">
                @if ($analysis)
                    @include('insurance-analyses.partials.company-details')
                @endif
            </div>
        @endforeach
    </div>
</section>
