@php
    $analysesByProvider = collect($companies)->keyBy('provider');
@endphp
<section class="border-top pt-4 mb-4" aria-labelledby="companies-title">
    <h2 id="companies-title" class="h5 mb-3">Todas as companhias</h2>
    <div class="row g-3">
        @foreach ($providerNames as $provider => $providerName)
            @php
                $blocked = ! in_array($provider, $activeProviders, true);
                $analysis = $blocked ? null : $analysesByProvider->get($provider);
            @endphp
            <div class="col-12 col-md-6 col-xl-3">
                <article @class(['card h-100', 'bg-body-tertiary' => $blocked]) aria-labelledby="provider-{{ $provider }}" data-provider="{{ $provider }}">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h3 id="provider-{{ $provider }}" class="h6 mb-0">{{ $providerName }}</h3>
                        @if ($blocked)
                            <span class="badge text-bg-secondary">Bloqueada</span>
                        @else
                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Edição disponível em uma próxima etapa">Editar</button>
                        @endif
                    </div>
                    <div class="card-body">
                        @if ($blocked)
                            <p class="text-body-secondary">Aguardando testes e validação da integração.</p>
                            <button type="button" class="btn btn-outline-secondary w-100" disabled>Indisponível</button>
                        @elseif (! $analysis)
                            <p class="mb-0" role="status">Aguardando análise desta companhia.</p>
                        @else
                            @php($price = $analysis['quote']['price'])
                            <p><span @class(['badge', 'text-bg-success' => $analysis['status'] === 'approved', 'text-bg-danger' => in_array($analysis['status'], ['rejected', 'failed'], true), 'text-bg-secondary' => ! in_array($analysis['status'], ['approved', 'rejected', 'failed'], true)])>{{ $statusLabels[$analysis['status']] ?? $analysis['status'] }}</span></p>
                            <dl class="mb-3">
                                <dt>Retorno da companhia</dt><dd class="text-break">{{ $analysis['provider_status'] ?: 'Aguardando retorno' }}</dd>
                                <dt>Produto</dt><dd>{{ $analysis['product'] === 'fianca_locaticia' ? 'Fiança locatícia' : $analysis['product'] }}</dd>
                                <dt>Orçamento / proposta</dt><dd class="text-break">{{ $analysis['quote']['quote_number'] ?? $analysis['quote']['quote_id'] ?? $analysis['quote']['proposal_id'] ?? 'Não informado' }}</dd>
                                <dt>Rodada</dt><dd class="text-break">{{ $analysis['attempt']['attempt_id'] ?? 'Não informada' }}{{ ($analysis['attempt']['is_reanalysis'] ?? false) ? ' (reanálise)' : '' }}</dd>
                            </dl>
                            @if ($analysis['status'] === 'approved' && $price['total'] !== null)
                                <p class="fw-semibold mb-1">{{ $formatMoney($price['total'], $price['currency']) }}</p>
                                <p class="small text-body-secondary">{{ $price['basis'] === 'gross_total' ? 'Prêmio bruto total da vigência' : 'Valor informado; total ainda não confirmado para comparação' }}</p>
                            @else
                                <p class="text-body-secondary">Sem preço aprovado disponível.</p>
                            @endif
                            <p class="small">Vigência: {{ $formatDate($price['period_start']) }} até {{ $formatDate($price['period_end']) }}</p>
                            <h4 class="h6">Coberturas retornadas</h4>
                            <ul class="list-group list-group-flush">
                                @forelse ($analysis['quote']['coverages'] as $coverage)
                                    <li class="list-group-item px-0">
                                        <strong class="d-block">{{ $coverage['name'] ?? 'Cobertura sem identificação' }}</strong>
                                        @if ($coverage['contracted'] !== null)
                                            <span class="small d-block">{{ $coverage['contracted'] ? 'Contratada' : 'Não contratada' }}</span>
                                        @endif
                                        @if ($coverage['insured_amount'] !== null)
                                            <span class="small d-block">Limite segurado: {{ $formatMoney($coverage['insured_amount'], $price['currency']) }}</span>
                                        @endif
                                        @if ($coverage['declared_amount'] !== null)
                                            <span class="small d-block">Verba: {{ $formatMoney($coverage['declared_amount'], $price['currency']) }}</span>
                                        @endif
                                        <span class="small d-block">{{ $coverage['premium_basis'] === 'net' ? 'Prêmio líquido' : 'Prêmio informado' }}: {{ $formatMoney($coverage['premium'], $price['currency']) }}</span>
                                        @if ($coverage['indemnity_period'] !== null)
                                            <span class="small d-block">Período indenitário: {{ $coverage['indemnity_period'] }}</span>
                                        @endif
                                    </li>
                                @empty
                                    <li class="list-group-item px-0 text-body-secondary">Sem coberturas disponíveis neste retorno.</li>
                                @endforelse
                            </ul>
                        @endif
                    </div>
                    @if ($analysis)
                        <div class="card-footer small text-body-secondary">
                            Solicitação: {{ $formatDate($analysis['requested_at']) }}<br>
                            Finalização: {{ $analysis['finished_at'] ? $formatDate($analysis['finished_at']) : 'Aguardando' }}
                        </div>
                    @endif
                </article>
            </div>
        @endforeach
    </div>
</section>
