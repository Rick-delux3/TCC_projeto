@php
    $analysesByProvider = collect($companies)->keyBy('provider');
    $canEditExpenses = ($pageData['permissions']['edit_lead'] ?? false) && ($pageData['permissions']['create_analysis'] ?? false);
    $planNames = ['Basic' => 'Básico', 'Complete' => 'Completo', 'Traditional' => 'Tradicional', 'Tradicional' => 'Tradicional', 'default' => 'Seguro Aluguel'];
@endphp
<section class="ia-companies border-top pt-4 mb-4" aria-labelledby="companies-title">
    <h2 id="companies-title" class="h5 mb-3">Todas as companhias</h2>
    <div class="row g-3">
        @foreach ($providerNames as $provider => $providerName)
            @php
                $analysis = $analysesByProvider->get($provider);
                $dangerMessages = $provider === 'tokio' ? ($analysis['messages']['danger'] ?? []) : [];
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
                // Porto: o card mostra a Fiança e o Essencial mais barato do cenário
                // escolhido na rodada. Sem cenário aprovado, vale o estado da companhia.
                $portoAttempt = null;
                $portoCard = [];
                if ($provider === 'porto') {
                    $portoAttempt = $attempts->first(fn ($item) => $item['selected'] && $item['status'] === 'approved')
                        ?? $attempts->firstWhere('status', 'approved');
                    $portoCard = array_values(array_filter([
                        $portoAttempt['quote']['card']['fianca'] ?? null,
                        $portoAttempt['quote']['card']['essential'] ?? null,
                    ]));
                }
            @endphp
            <div class="col-12 col-md-6 col-xl-3" data-analysis-component="provider-{{ $provider }}" style="--ia-i: {{ $loop->index }}">
                <article @class(['ia-company card h-100', 'bg-body-tertiary' => $blocked, 'border-success border-2' => ! $blocked && $reanalysisSupported]) data-provider-read-only="{{ $readOnly ? 'true' : 'false' }}" aria-labelledby="provider-{{ $provider }}" data-provider="{{ $provider }}" data-analysis-state="{{ $blocked ? 'blocked' : ($notIncluded ? 'not_included' : ($analysis['display_state']['state'] ?? 'processing')) }}" aria-busy="{{ ! $blocked && ! $notIncluded && ($analysis['display_state']['processing'] ?? true) ? 'true' : 'false' }}">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h3 id="provider-{{ $provider }}" class="h6 mb-0">@if ($nvs)<span class="ia-mark" aria-hidden="true">{{ $providerMarks[$provider] }}</span>@endif{{ $providerName }}</h3>
                        @if ($blocked)
                            <span class="badge text-bg-secondary">Bloqueada</span>
                        @else
                            @if ($readOnly)
                                <span class="badge text-bg-secondary">Desativada</span>
                            @endif
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-analysis-dialog="details-{{ $provider }}" aria-haspopup="dialog" aria-controls="details-{{ $provider }}" @disabled(! $analysis)>Visualizar</button>
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
                        @elseif ($portoCard !== [])
                            <ul class="list-group list-group-flush">
                                @foreach ($portoCard as $portoPlan)
                                    <li class="list-group-item px-0" data-porto-plan="{{ $portoPlan['code'] }}">
                                        <h4 class="h6 d-flex flex-wrap justify-content-center align-items-center gap-2">
                                            {{ $portoPlan['name'] }}
                                            @if ($portoPlan['best'] ?? false)
                                                <span class="badge text-bg-success">Mais barato</span>
                                            @endif
                                        </h4>
                                        @if ((float) $portoPlan['installment_amount'] > 0)
                                            @include('insurance-analyses.partials.offer-price', ['offerStatus' => 'approved', 'amount' => $portoPlan['installment_amount'], 'count' => $portoPlan['installments'], 'manualMessage' => $analysis['manual_message'], 'displayState' => ['state' => 'approved', 'processing' => false]])
                                        @else
                                            <p class="text-body-secondary mb-0" role="status">Preço indisponível no momento.</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @elseif ($plans->isNotEmpty())
                            <ul class="list-group list-group-flush">
                                @foreach ($plans as $plan)
                                    <li class="list-group-item px-0">
                                        <h4 class="h6">{{ $planNames[$plan['plan_key']] ?? $plan['plan_key'] ?: 'Seguro Aluguel' }}</h4>
                                        @include('insurance-analyses.partials.offer-price', ['offerStatus' => $plan['status'], 'amount' => $plan['payment_status'] === 'ready' ? $plan['installment_amount'] : null, 'count' => $plan['installments'], 'recurring' => $plan['recurring_installment'] ?? null, 'manualMessage' => $analysis['manual_message'], 'displayState' => $plan['display_state']])
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            @php($price = $analysis['quote']['price'])
                            @include('insurance-analyses.partials.offer-price', ['offerStatus' => $analysis['status'], 'amount' => $price['installment_amount'] ?? null, 'count' => $price['installments'] ?? null, 'manualMessage' => $analysis['manual_message'], 'displayState' => $analysis['display_state']])
                        @endif
                        @php($refusalLetter = $analysis['actions']['refusal_letter'] ?? ['available' => false, 'url' => null])
                    </div>
                    @if ($provider !== 'tokio' && $analysis && $refusalLetter['available'] && filled($refusalLetter['url']))
                        <div class="px-3 pb-3 d-flex flex-column gap-2">
                            <a href="{{ $refusalLetter['url'] }}"
                                class="ia-refusal-download btn btn-outline-primary d-flex align-items-center justify-content-center gap-2 px-3 py-3 w-100"
                                data-refusal-letter="{{ $provider }}" rel="nofollow" type="application/pdf"
                                aria-label="Baixar carta de recusa da {{ $providerName }}, em PDF"
                                aria-describedby="refusal-letter-description-{{ $provider }}">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="flex-shrink-0">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-linejoin="round" />
                                    <path d="M14 2v6h6M12 11v7m-3-3 3 3 3-3" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <span>Baixar carta de recusa</span>
                            </a>
                            <p id="refusal-letter-description-{{ $provider }}" class="small text-body-secondary text-center mb-0">{{ ($refusalLetter['scenario'] ?? null) === 'joint' ? 'PDF da análise conjunta.' : 'Documento em PDF.' }}</p>
                        </div>
                    @elseif ($provider === 'tokio' && ($analysis['status'] ?? null) === 'rejected')
                        <div class="px-3 pb-3" data-refusal-letter-unavailable="tokio">
                            <p class="small text-body-secondary mb-0" role="status">Carta de recusa não disponivel</p>
                        </div>
                    @endif
                    @php($quotePdf = $analysis['actions']['quote_pdf'] ?? ['state' => null, 'url' => null])
                    {{-- PDF da cotação: só companhia aprovada; a cotação é a exibida no card. --}}
                    @if ($analysis && ($quotePdf['state'] ?? null) === 'available' && filled($quotePdf['url']))
                        <div class="px-3 pb-3 d-flex flex-column gap-2">
                            <a href="{{ $quotePdf['url'] }}"
                                class="ia-quote-download btn btn-outline-primary d-flex align-items-center justify-content-center gap-2 px-3 py-3 w-100"
                                data-quote-pdf="{{ $provider }}" rel="nofollow" type="application/pdf"
                                aria-label="Baixar cotação da {{ $providerName }}, em PDF"
                                aria-describedby="quote-pdf-description-{{ $provider }}">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false" class="flex-shrink-0" data-idle-icon>
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-linejoin="round" />
                                    <path d="M14 2v6h6M12 11v7m-3-3 3 3 3-3" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <span class="spinner-border spinner-border-sm flex-shrink-0 d-none" aria-hidden="true" data-loading-indicator></span>
                                <span data-button-label>Baixar cotação</span>
                            </a>
                            <p id="quote-pdf-description-{{ $provider }}" class="small text-body-secondary text-center mb-0">{{ ($quotePdf['scenario'] ?? null) === 'joint' ? 'PDF da análise conjunta.' : 'Documento em PDF.' }}</p>
                            <p class="small text-danger text-center mb-0 d-none" role="alert" data-quote-pdf-error></p>
                        </div>
                    @elseif ($analysis && ($quotePdf['state'] ?? null) === 'expired')
                        <div class="px-3 pb-3" data-quote-pdf-state="expired">
                            <p class="small text-body-secondary text-center mb-0" role="status">Cotação expirada</p>
                        </div>
                    @elseif ($analysis && ($quotePdf['state'] ?? null) === 'unsupported')
                        <div class="px-3 pb-3" data-quote-pdf-state="unsupported">
                            <p class="small text-body-secondary text-center mb-0" role="status">PDF da cotação indisponível para esta companhia</p>
                        </div>
                    @endif
                    @if ($blocked)
                        {{-- Companhia desligada no .env: sem aviso de reanálise. --}}
                    @elseif ($reanalysisSupported)
                        {{-- A ação fica junto do aviso: só aparece para quem pode editar as despesas. --}}
                        <div class="card-footer bg-success-subtle border-success text-success-emphasis small fw-semibold py-3 d-flex flex-wrap justify-content-between align-items-center gap-2" data-reanalysis-notice="supported">
                            <span>Esta companhia permite reanálise.</span>
                            @if (! $readOnly && $canEditExpenses)
                                @php($providerReanalysis = $analysis['actions']['expense_reanalysis'] ?? ['available' => false, 'url' => null])
                                <button type="button" class="btn btn-sm btn-primary"
                                    data-provider-reanalysis="{{ $provider }}" data-provider-name="{{ $providerName }}" data-url="{{ $providerReanalysis['url'] }}"
                                    @disabled(! $providerReanalysis['available'])
                                    @unless ($providerReanalysis['available']) title="Disponível quando esta companhia concluir a análise atual." @endunless>
                                    <span class="spinner-border spinner-border-sm me-1 d-none" aria-hidden="true" data-loading-indicator></span>
                                    <span data-button-label>Reanalisar</span>
                                </button>
                            @endif
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
