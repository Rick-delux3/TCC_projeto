@extends('layout-inicial.Dashboard_User')

@section('content_w')
@php
    $statusLabels = [
        'novo' => 'Novo',
        'em-andamento' => 'Em andamento',
        'qualificado' => 'Qualificado',
        'convertido' => 'Convertido',
        'perdido' => 'Perdido',
    ];

    $totalLeads = $dashboardStats['totalLeads'] ?? 0;
    $newLeads = $dashboardStats['newLeads'] ?? 0;
    $recentLeads = $dashboardStats['recentLeads'] ?? 0;
    $withPhone = $dashboardStats['withPhone'] ?? 0;
    $withoutPhone = $dashboardStats['withoutPhone'] ?? 0;
    $latestLeadAt = $dashboardStats['latestLeadAt'] ?? null;
    $filteredLeads = $dashboardStats['filteredLeads'] ?? $leads->total();
    $topTags = $topTags ?? collect();
    $filterTags = $filterTags ?? collect();
    $selectedTag = $selectedTag ?? '';
    $isTagFiltered = filled($selectedTag);
    $currentStart = $leads->firstItem() ?? 0;
    $currentEnd = $leads->lastItem() ?? 0;
@endphp

<div class="crm-dashboard">
    <section class="crm-hero">
        <div class="crm-hero__main">
            <span class="crm-pill">CRM Imobiliario</span>
            <h1>Painel de leads para imobiliarias com leitura rapida e acao imediata.</h1>
            <p>
                Acompanhe a entrada de contatos, priorize atendimentos e organize o fluxo comercial
                em uma interface mais clara, moderna e preparada para crescimento.
            </p>

            <div class="crm-hero__highlights">
                <div class="crm-hero__highlight">
                    <strong>{{ $totalLeads }}</strong>
                    <span>leads sincronizados</span>
                </div>

                <div class="crm-hero__highlight">
                    <strong>{{ $newLeads }}</strong>
                    <span>em fase inicial</span>
                </div>

                <div class="crm-hero__highlight">
                    <strong>{{ $recentLeads }}</strong>
                    <span>entradas nos ultimos 7 dias</span>
                </div>
            </div>
        </div>

        <aside class="crm-hero__aside">
            <span class="crm-pill crm-pill--light">Visao operacional</span>
            <h2>Central de acompanhamento comercial</h2>
            <p>
                O painel abaixo foi organizado para facilitar triagem, leitura de origem dos leads
                e futuras automacoes de atendimento.
            </p>

            <ul class="crm-checklist">
                <li>Priorize os contatos com telefone para retorno rapido.</li>
                <li>Use as tags para entender a origem e o perfil da captacao.</li>
                <li>Conecte os botoes de acao quando suas rotas estiverem prontas.</li>
            </ul>

            <div class="crm-hero__stamp">
                <span>Ultima entrada registrada</span>
                <strong>{{ $latestLeadAt ? $latestLeadAt->format('d/m/Y H:i') : 'Sem leads sincronizados' }}</strong>
            </div>
        </aside>
    </section>

    <section class="crm-kpis">
        <article class="crm-kpi">
            <span class="crm-kpi__label">Base ativa</span>
            <strong>{{ $totalLeads }}</strong>
            <p>Quantidade total de leads disponiveis para o time comercial.</p>
        </article>

        <article class="crm-kpi">
            <span class="crm-kpi__label">Contato direto</span>
            <strong>{{ $withPhone }}</strong>
            <p>Leads com telefone preenchido para abordagem mais imediata.</p>
        </article>

        <article class="crm-kpi">
            <span class="crm-kpi__label">Pendentes de enriquecimento</span>
            <strong>{{ $withoutPhone }}</strong>
            <p>Registros que ainda pedem complemento de telefone ou revisao.</p>
        </article>

        <article class="crm-kpi">
            <span class="crm-kpi__label">Recencia</span>
            <strong>{{ $recentLeads }}</strong>
            <p>Leads captados recentemente, com maior chance de resposta rapida.</p>
        </article>
    </section>

    <section class="crm-content">
        <div class="crm-card crm-card--table">
            <div class="crm-card__header">
                <div>
                    <span class="crm-section-tag">Fila comercial</span>
                    <h2>Leads em acompanhamento</h2>
                    <p>Visualize os contatos sincronizados e prepare os proximos passos de atendimento.</p>
                </div>

                <div class="crm-card__meta">
                    <span>{{ $isTagFiltered ? $filteredLeads . ' filtrados' : $totalLeads . ' registros' }}</span>
                </div>
            </div>

            <div class="crm-filter-panel">
                <form method="GET" action="{{ url()->current() }}" class="crm-filter-form">
                    <label class="crm-filter-field" for="crm-tag-filter">
                        <span>Filtrar por tag</span>
                        <select id="crm-tag-filter" name="tag" class="crm-filter-select">
                            <option value="">Todas as tags</option>
                            @foreach ($filterTags as $tag => $count)
                                <option value="{{ $tag }}" @selected($selectedTag === $tag)>
                                    {{ $tag }} ({{ $count }})
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <div class="crm-filter-actions">
                        <button type="submit" class="crm-filter-submit">Filtrar</button>

                        @if ($isTagFiltered)
                            <a href="{{ url()->current() }}" class="crm-filter-clear">Limpar</a>
                        @endif
                    </div>
                </form>

                @if ($filterTags->isNotEmpty())
                    <div class="crm-quick-filters">
                        @foreach ($filterTags->take(8) as $tag => $count)
                            <a
                                href="{{ request()->fullUrlWithQuery(['tag' => $tag, 'page' => 1]) }}"
                                class="crm-quick-filter {{ $selectedTag === $tag ? 'crm-quick-filter--active' : '' }}"
                            >
                                <span>{{ $tag }}</span>
                                <strong>{{ $count }}</strong>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if ($isTagFiltered)
                    <p class="crm-filter-feedback">
                        Mostrando {{ $filteredLeads }} lead{{ $filteredLeads === 1 ? '' : 's' }} com a tag
                        <strong>{{ $selectedTag }}</strong>.
                    </p>
                @endif
            </div>

            @if ($leads->total() > 0)
                <div class="crm-table-wrap">
                    <table class="crm-table">
                        <thead>
                            <tr>
                                <th>Lead</th>
                                <th>Contato</th>
                                <th>Segmentacao</th>
                                <th>Entrada</th>
                                <th>Status</th>
                                <th>Acoes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($leads as $lead)
                                @php
                                    $leadName = $lead->nome ?: 'Lead sem nome';
                                    $leadEmail = $lead->email ?: 'Email nao informado';
                                    $leadPhone = $lead->tel ?: 'Telefone nao informado';
                                    $leadCity = $lead->cidade ?: 'Cidade nao informada';
                                    $leadDate = $lead->created_at ? $lead->created_at->format('d/m/Y') : 'Sem data';
                                    $leadTime = $lead->created_at ? $lead->created_at->format('H:i') : '--:--';
                                    $statusKey = \Illuminate\Support\Str::slug($lead->status ?: 'novo');
                                    $statusLabel = $statusLabels[$statusKey] ?? ucfirst(str_replace('-', ' ', $statusKey));
                                    $allTags = collect(preg_split('/\s*,\s*/', $lead->tags_originais ?? ''))
                                        ->filter(fn ($tag) => filled($tag));
                                    $visibleTags = $allTags->take(2);
                                    $remainingTags = max($allTags->count() - $visibleTags->count(), 0);
                                    $leadInitials = collect(preg_split('/\s+/', trim($leadName)))
                                        ->filter()
                                        ->take(2)
                                        ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
                                        ->implode('');
                                @endphp

                                <tr>
                                    <td data-label="Lead">
                                        <div class="crm-lead">
                                            <div class="crm-lead__avatar">{{ $leadInitials ?: 'L' }}</div>

                                            <div class="crm-lead__info">
                                                <strong>{{ $leadName }}</strong>
                                                <span>{{ $leadCity }}</span>
                                            </div>
                                        </div>
                                    </td>

                                    <td data-label="Contato">
                                        <div class="crm-contact">
                                            @if ($lead->email)
                                                <a href="mailto:{{ $lead->email }}">{{ $leadEmail }}</a>
                                            @else
                                                <span class="crm-contact__email">{{ $leadEmail }}</span>
                                            @endif

                                            <span>{{ $leadPhone }}</span>
                                        </div>
                                    </td>

                                    <td data-label="Segmentacao">
                                        <div class="crm-tags">
                                            @forelse ($visibleTags as $tag)
                                                <a
                                                    href="{{ request()->fullUrlWithQuery(['tag' => $tag, 'page' => 1]) }}"
                                                    class="crm-tag crm-tag--link {{ $selectedTag === $tag ? 'crm-tag--active' : '' }}"
                                                >
                                                    {{ $tag }}
                                                </a>
                                            @empty
                                                <span class="crm-tag crm-tag--muted">Sem tag</span>
                                            @endforelse

                                            @if ($remainingTags > 0)
                                                <span class="crm-tag crm-tag--muted">+{{ $remainingTags }}</span>
                                            @endif
                                        </div>
                                    </td>

                                    <td data-label="Entrada">
                                        <div class="crm-date">
                                            <strong>{{ $leadDate }}</strong>
                                            <span>{{ $leadTime }}</span>
                                        </div>
                                    </td>

                                    <td data-label="Status">
                                        <span class="crm-status crm-status--{{ $statusKey }}">{{ $statusLabel }}</span>
                                    </td>

                                    <td data-label="Acoes">
                                        <div class="crm-actions">
                                            <button type="button" class="crm-action crm-action--ghost">Analisar</button>
                                            <button type="button" class="crm-action">Visualizar</button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="crm-table-footer">
                    <p class="crm-table-footer__summary">
                        Exibindo {{ $currentStart }} a {{ $currentEnd }} de {{ $filteredLeads }} leads{{ $isTagFiltered ? ' filtrados' : '' }}
                    </p>

                    @if ($leads->hasPages())
                        <div class="crm-pagination">
                            {{ $leads->onEachSide(1)->links('pagination::bootstrap-5') }}
                        </div>
                    @endif
                </div>
            @else
                <div class="crm-empty">
                    <span class="crm-section-tag">Base vazia</span>
                    @if ($isTagFiltered)
                        <h3>Nenhum lead encontrado com a tag {{ $selectedTag }}.</h3>
                        <p>
                            Tente escolher outra tag ou limpe o filtro para voltar a visualizar toda a base
                            sincronizada da imobiliaria.
                        </p>
                        <a href="{{ url()->current() }}" class="crm-filter-clear crm-filter-clear--inline">Limpar filtro</a>
                    @else
                        <h3>Nenhum lead encontrado para esta imobiliaria.</h3>
                        <p>
                            Assim que a sincronizacao trouxer novos contatos, eles serao exibidos aqui com
                            indicadores, tags e acoes de acompanhamento.
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <aside class="crm-sidebar">
            <div class="crm-card">
                <div class="crm-card__header crm-card__header--stack">
                    <div>
                        <span class="crm-section-tag">Origem e interesse</span>
                        <h2>Tags com maior volume</h2>
                    </div>
                </div>

                <div class="crm-source-list">
                    @forelse ($topTags as $tag => $count)
                        <a
                            href="{{ request()->fullUrlWithQuery(['tag' => $tag, 'page' => 1]) }}"
                            class="crm-source-item crm-source-item--link {{ $selectedTag === $tag ? 'crm-source-item--active' : '' }}"
                        >
                            <div>
                                <strong>{{ $tag }}</strong>
                                <span>Leads segmentados por esta tag</span>
                            </div>

                            <span class="crm-source-count">{{ $count }}</span>
                        </a>
                    @empty
                        <p class="crm-muted-copy">As tags de origem vao aparecer aqui assim que os primeiros leads forem sincronizados.</p>
                    @endforelse
                </div>
            </div>

            <div class="crm-card">
                <div class="crm-card__header crm-card__header--stack">
                    <div>
                        <span class="crm-section-tag">Ritmo de operacao</span>
                        <h2>Leitura rapida da base</h2>
                    </div>
                </div>

                <div class="crm-quick-grid">
                    <div class="crm-quick-metric">
                        <span>Novos</span>
                        <strong>{{ $newLeads }}</strong>
                    </div>

                    <div class="crm-quick-metric">
                        <span>Com telefone</span>
                        <strong>{{ $withPhone }}</strong>
                    </div>

                    <div class="crm-quick-metric">
                        <span>Sem telefone</span>
                        <strong>{{ $withoutPhone }}</strong>
                    </div>

                    <div class="crm-quick-metric">
                        <span>Ultimos 7 dias</span>
                        <strong>{{ $recentLeads }}</strong>
                    </div>
                </div>
            </div>
        </aside>
    </section>
</div>
@endsection
