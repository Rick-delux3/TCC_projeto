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
    $sessionSuccessMessage = session('success');
    $leadFormUrl = $leadFormUrl ?? null;
    $leadFormActive = $leadFormActive ?? false;
    $hasSyncFailed = $syncStatus === 'failed';
    $isSyncBusy = in_array($syncStatus, ['queued', 'running'], true);
    $showInitialSyncModal = in_array($syncStatus, ['queued', 'running'], true);
    $showFailedSyncModal = $hasSyncFailed;
    $showLeadFormModal = filled($leadFormUrl);
@endphp

<div
    id="sync-status-modal"
    class="sync-modal {{ $showInitialSyncModal || $showFailedSyncModal || $sessionSuccessMessage || $showLeadFormModal ? 'is-visible' : 'is-hidden' }}"
    data-state="{{ $syncStatus }}"
    data-variant="info"
    aria-hidden="{{ $showInitialSyncModal || $showFailedSyncModal || $sessionSuccessMessage || $showLeadFormModal ? 'false' : 'true' }}"
>
    <div class="sync-modal__backdrop" data-sync-dismiss></div>

    <div class="sync-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="sync-modal-title">
        <div class="sync-modal__card">
            <button type="button" class="sync-modal__close" aria-label="Fechar" data-sync-dismiss>
                <span aria-hidden="true">&times;</span>
            </button>

            <div class="sync-modal__brand">
                <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="NVS Seguros">
                <span>SEGUROS</span>
            </div>

            <span id="sync-modal-badge" class="sync-modal__badge">Sincronizacao ativa</span>
            <h2 id="sync-modal-title" class="sync-modal__title">Sincronizando leads...</h2>
            <p id="sync-modal-description" class="sync-modal__description">
                Processando e sincronizando novos leads entre a API e a base de dados local.
            </p>

            <div class="sync-modal__progress">
                <div class="sync-modal__track">
                    <div id="sync-modal-progress-bar" class="sync-modal__fill" style="width: 0%;"></div>
                    <span id="sync-modal-progress-thumb" class="sync-modal__thumb" style="left: 0%;"></span>
                </div>

                <div class="sync-modal__progress-copy">
                    <strong id="sync-modal-percent">0%</strong>
                    <span id="sync-modal-summary">Aguardando inicio do processamento.</span>
                </div>
            </div>

            <section class="sync-modal__lead-card" aria-labelledby="lead-form-card-title">
                <div class="sync-modal__lead-copy">
                    <span class="sync-modal__lead-tag">CaptaÃƒÂ§ÃƒÂ£o externa</span>
                    <h3 id="lead-form-card-title" class="sync-modal__lead-title">Link rÃƒÂ¡pido do formulÃƒÂ¡rio de lead</h3>
                    <p id="lead-form-card-description" class="sync-modal__lead-description">
                        Compartilhe este acesso com clientes ou pÃƒÂ¡ginas de captura para receber novos leads sem entrar no painel.
                    </p>
                </div>

                <div class="sync-modal__lead-field">
                    <input
                        type="text"
                        class="sync-modal__lead-input"
                        value="{{ $leadFormUrl ?? '' }}"
                        readonly
                        id="leadFormLink"
                        @disabled(blank($leadFormUrl))
                    >

                    <button
                        class="sync-modal__lead-copy-btn"
                        type="button"
                        id="leadFormCopyButton"
                        @disabled(blank($leadFormUrl))
                    >
                        Copiar link
                    </button>
                </div>

                <div class="sync-modal__lead-actions">
                    <a
                        href="{{ $leadFormUrl ?? '#' }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="sync-modal__lead-open {{ blank($leadFormUrl) ? 'is-disabled' : '' }}"
                        id="leadFormOpenButton"
                        @if (blank($leadFormUrl)) aria-disabled="true" tabindex="-1" @endif
                    >
                        Abrir formulÃƒÂ¡rio
                    </a>

                    <span
                        id="leadFormCopyStatus"
                        class="sync-modal__lead-status {{ blank($leadFormUrl) ? 'is-muted' : '' }}"
                    >
                        {{ blank($leadFormUrl) ? 'FormulÃƒÂ¡rio indisponÃƒÂ­vel no momento.' : 'DisponÃƒÂ­vel para compartilhamento imediato.' }}
                    </span>
                </div>
            </section>

            <div class="sync-modal__pipeline" aria-hidden="true">
                <div class="sync-modal__endpoint">
                    <span class="sync-modal__endpoint-icon">DB</span>
                    <small>Local DB</small>
                </div>

                <div class="sync-modal__pipeline-line"></div>

                <div class="sync-modal__endpoint">
                    <span class="sync-modal__endpoint-icon">API</span>
                    <small>LeadLovers API</small>
                </div>
            </div>

            <p id="sync-modal-footnote" class="sync-modal__footnote">
                Este processo pode levar alguns segundos.
            </p>

            <div class="sync-modal__actions">
                <button type="button" class="sync-modal__secondary" data-sync-dismiss>
                    Continuar no painel
                </button>

                <button type="button" class="sync-modal__primary is-hidden" id="sync-modal-primary-action">
                    Atualizar painel
                </button>
            </div>

            <form method="POST" action="{{ route('Dashboard.syncAgain') }}" id="sync-modal-retry-form" class="sync-modal__retry-form">
                @csrf
            </form>
        </div>
    </div>
</div>
<div class="crm-dashboard">
    <section class="crm-hero">
        <div class="crm-hero__main">
            <span class="crm-pill">CRM Imobiliario</span>
            <h1>Painel de leads para imobiliarias com leitura rapida e acao imediata.</h1>
            <p>
                Acompanhe a entrada de contatos, priorize atendimentos e organize o fluxo comercial
                em uma interface mais clara, moderna e preparada para crescimento.
            </p>

            <div class="crm-hero__actions">
                <form method="POST" action="{{ route('Dashboard.syncAgain') }}" class="crm-sync-form">
                    @csrf
                    <button
                        type="submit"
                        class="crm-sync-button {{ $isSyncBusy ? 'is-busy' : '' }} {{ $hasSyncFailed ? 'is-error' : '' }}"
                        @disabled($isSyncBusy)
                    >
                        {{ $isSyncBusy ? 'Sincronizacao em andamento' : ($hasSyncFailed ? 'Tentar sincronizacao novamente' : 'Sincronizar novamente') }}
                    </button>
                </form>

                <span class="crm-sync-hint {{ $hasSyncFailed ? 'is-error' : '' }}">
                    {{ $hasSyncFailed
                        ? 'A ultima sincronizacao falhou. Use o botao ao lado para tentar novamente a importacao dos leads.'
                        : ($isSyncBusy
                        ? 'Sua fila ja esta ativa. O modal acompanha o progresso em tempo real.'
                        : 'Use esta acao quando quiser puxar uma nova rodada de leads da integracao.') }}
                </span>
            </div>

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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('sync-status-modal');
    const statusUrl = "{{ route('Dashboard.syncStatus') }}";
    const currentStatus = @json($syncStatus);
    const initialSyncError = @json($syncError);
    const initialTotalLeads = @json($totalLeads);
    const sessionSuccessMessage = @json($sessionSuccessMessage);
    const leadFormUrl = @json($leadFormUrl);
    const leadFormActive = @json($leadFormActive);
    const showLeadFormModal = @json($showLeadFormModal);

    if (!modal) {
        return;
    }

    const badgeEl = document.getElementById('sync-modal-badge');
    const titleEl = document.getElementById('sync-modal-title');
    const descriptionEl = document.getElementById('sync-modal-description');
    const progressBarEl = document.getElementById('sync-modal-progress-bar');
    const progressThumbEl = document.getElementById('sync-modal-progress-thumb');
    const percentEl = document.getElementById('sync-modal-percent');
    const summaryEl = document.getElementById('sync-modal-summary');
    const footnoteEl = document.getElementById('sync-modal-footnote');
    const primaryActionEl = document.getElementById('sync-modal-primary-action');
    const secondaryActionEl = modal.querySelector('.sync-modal__secondary');
    const dismissEls = modal.querySelectorAll('[data-sync-dismiss]');
    const retryFormEl = document.getElementById('sync-modal-retry-form');
    const leadFormCopyButton = document.getElementById('leadFormCopyButton');
    const leadFormInput = document.getElementById('leadFormLink');
    const leadFormCopyStatus = document.getElementById('leadFormCopyStatus');
    const leadFormOpenButton = document.getElementById('leadFormOpenButton');

    let intervalId = null;
    let suppressLiveModal = false;
    let doneReloadTimeout = null;
    let copyFeedbackTimeout = null;

    function openModal(force) {
        if (force) {
            suppressLiveModal = false;
        }

        modal.classList.remove('is-hidden');
        modal.classList.add('is-visible');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        modal.classList.remove('is-visible');
        modal.classList.add('is-hidden');
        modal.setAttribute('aria-hidden', 'true');

        if (modal.dataset.state === 'queued' || modal.dataset.state === 'running') {
            suppressLiveModal = true;
        }
    }

    function progressForStatus(status, totalLeads) {
        if (status === 'queued') {
            return 18;
        }

        if (status === 'running') {
            return Math.min(84, 46 + Math.min(Number(totalLeads || 0), 38));
        }

        if (status === 'lead-hub') {
            return leadFormActive ? 100 : 0;
        }

        return 100;
    }

    function getModalCopy(status, payload) {
        const leadsCount = Number(payload.totalLeads || 0);
        const progress = progressForStatus(status, leadsCount);

        if (status === 'queued') {
            return {
                state: 'queued',
                variant: 'info',
                badge: 'Fila inteligente',
                title: 'Preparando sincronizacao de leads',
                description: 'Organizando a fila de importacao entre a LeadLovers e a base local, enquanto o formulario abaixo ja pode ser compartilhado.',
                progress: progress,
                summary: 'Aguardando inicio do processamento',
                footnote: 'Voce pode continuar no painel enquanto a fila e preparada.',
                primaryLabel: ''
            };
        }

        if (status === 'running') {
            return {
                state: 'running',
                variant: 'info',
                badge: 'Sincronizacao em andamento',
                title: 'Sincronizando leads...',
                description: 'Processando e sincronizando novos leads entre a API e a base de dados local. O link de captacao segue disponivel logo abaixo.',
                progress: progress,
                summary: leadsCount > 0 ? `${leadsCount} leads processados ate agora` : 'Lendo os primeiros registros da integracao',
                footnote: 'Este processo pode levar alguns segundos.',
                primaryLabel: ''
            };
        }

        if (status === 'done') {
            return {
                state: 'done',
                variant: 'success',
                badge: 'Base atualizada',
                title: 'Leads sincronizados com sucesso',
                description: 'Sua base local foi atualizada e o painel esta pronto para refletir os dados mais recentes. O formulario de captacao continua liberado para novos envios.',
                progress: 100,
                summary: `${leadsCount} leads disponiveis no painel`,
                footnote: 'Atualizando a interface automaticamente.',
                primaryLabel: 'Atualizar agora'
            };
        }

        if (status === 'failed') {
            return {
                state: 'failed',
                variant: 'error',
                badge: 'Sincronizacao interrompida',
                title: 'Nao foi possivel concluir a sincronizacao',
                description: payload.syncError || 'A sincronizacao encontrou uma falha entre a API e a base local. O link de captacao pode continuar sendo usado se estiver ativo.',
                progress: 100,
                summary: 'Revise a integracao e tente novamente mais tarde.',
                footnote: 'Voce pode continuar no painel enquanto investigamos a falha.',
                primaryLabel: 'Sincronizar novamente'
            };
        }

        if (status === 'lead-hub') {
            return {
                state: 'lead-hub',
                variant: leadFormActive ? 'info' : 'error',
                badge: leadFormActive ? 'Captacao pronta' : 'Captacao indisponivel',
                title: leadFormActive ? 'Seu formulario de leads esta pronto' : 'O formulario de leads esta indisponivel',
                description: leadFormActive
                    ? 'Use este espaco para compartilhar o formulario de captacao com clientes, portais ou paginas externas.'
                    : 'Ative novamente o formulario publico para voltar a receber leads externos por este acesso.',
                progress: progress,
                summary: leadFormActive ? 'Link seguro gerado para a sua imobiliaria' : 'Nenhum link ativo para compartilhamento',
                footnote: leadFormActive
                    ? 'Voce pode copiar o link, abrir o formulario e continuar no painel sem interromper a operacao.'
                    : 'Assim que o formulario for reativado, o link voltara a aparecer aqui.',
                primaryLabel: leadFormActive ? 'Abrir formulario' : ''
            };
        }

        return {
            state: 'flash-success',
            variant: 'success',
            badge: 'Tudo certo',
            title: 'Painel pronto para uso',
            description: payload.message || 'Operacao concluida com sucesso.',
            progress: 100,
            summary: 'Ambiente liberado para atendimento',
            footnote: 'Voce ja pode seguir com sua operacao.',
            primaryLabel: 'Fechar'
        };
    }

    function renderModal(copy) {
        modal.dataset.state = copy.state;
        modal.dataset.variant = copy.variant;

        badgeEl.textContent = copy.badge;
        titleEl.textContent = copy.title;
        descriptionEl.textContent = copy.description;
        percentEl.textContent = `${copy.progress}%`;
        summaryEl.textContent = copy.summary;
        footnoteEl.textContent = copy.footnote;
        progressBarEl.style.width = `${copy.progress}%`;
        progressThumbEl.style.left = `${copy.progress}%`;

        if (copy.primaryLabel) {
            primaryActionEl.textContent = copy.primaryLabel;
            primaryActionEl.classList.remove('is-hidden');
        } else {
            primaryActionEl.classList.add('is-hidden');
        }

        secondaryActionEl.textContent = copy.state === 'done' ? 'Fechar' : 'Continuar no painel';
    }

    function setLeadCopyStatus(message, variant) {
        if (!leadFormCopyStatus) {
            return;
        }

        leadFormCopyStatus.textContent = message;
        leadFormCopyStatus.classList.remove('is-success', 'is-error', 'is-muted');

        if (variant) {
            leadFormCopyStatus.classList.add(variant);
        }
    }

    dismissEls.forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    primaryActionEl.addEventListener('click', function () {
        if (modal.dataset.state === 'lead-hub' && leadFormUrl) {
            window.open(leadFormUrl, '_blank', 'noopener');
            return;
        }

        if (modal.dataset.state === 'failed' && retryFormEl) {
            retryFormEl.submit();
            return;
        }

        if (modal.dataset.state === 'done') {
            window.location.reload();
            return;
        }

        closeModal();
    });

    if (leadFormOpenButton) {
        leadFormOpenButton.addEventListener('click', function (event) {
            if (!leadFormUrl) {
                event.preventDefault();
            }
        });
    }

    if (leadFormCopyButton) {
        leadFormCopyButton.addEventListener('click', async function () {
            if (!leadFormUrl || !leadFormInput) {
                setLeadCopyStatus('Formulario indisponivel para copia.', 'is-error');
                return;
            }

            try {
                await navigator.clipboard.writeText(leadFormUrl);
                setLeadCopyStatus('Link copiado com sucesso.', 'is-success');

                if (copyFeedbackTimeout) {
                    clearTimeout(copyFeedbackTimeout);
                }

                copyFeedbackTimeout = setTimeout(function () {
                    setLeadCopyStatus('Disponivel para compartilhamento imediato.', '');
                }, 2600);
            } catch (error) {
                leadFormInput.focus();
                leadFormInput.select();
                setLeadCopyStatus('Nao foi possivel copiar automaticamente. Use Ctrl+C.', 'is-error');
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-visible')) {
            closeModal();
        }
    });

    async function checkSyncStatus() {
        try {
            const response = await fetch(statusUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();

            if (!data.authenticated) {
                return;
            }

            if (data.sync_status === 'queued' || data.sync_status === 'running') {
                renderModal(getModalCopy(data.sync_status, {
                    totalLeads: data.total_leads,
                    syncError: data.sync_error,
                    message: ''
                }));

                if (!suppressLiveModal) {
                    openModal(false);
                }
            }

            if (data.sync_status === 'done') {
                clearInterval(intervalId);
                renderModal(getModalCopy('done', {
                    totalLeads: data.total_leads,
                    syncError: data.sync_error
                }));
                openModal(true);

                if (doneReloadTimeout) {
                    clearTimeout(doneReloadTimeout);
                }

                doneReloadTimeout = setTimeout(function () {
                    window.location.reload();
                }, 1800);
            }

            if (data.sync_status === 'failed') {
                clearInterval(intervalId);
                renderModal(getModalCopy('failed', {
                    totalLeads: data.total_leads,
                    syncError: data.sync_error
                }));
                openModal(true);
            }
        } catch (error) {
            console.error('Erro ao consultar status da sincronizacao:', error);
        }
    }

    if (currentStatus === 'queued' || currentStatus === 'running') {
        renderModal(getModalCopy(currentStatus, {
            totalLeads: initialTotalLeads,
            syncError: initialSyncError,
            message: ''
        }));
        openModal(true);
        intervalId = setInterval(checkSyncStatus, 5000);
        checkSyncStatus();
    } else if (currentStatus === 'failed') {
        renderModal(getModalCopy('failed', {
            totalLeads: initialTotalLeads,
            syncError: initialSyncError,
            message: ''
        }));
        openModal(true);
    } else if (sessionSuccessMessage) {
        renderModal(getModalCopy('flash-success', {
            totalLeads: initialTotalLeads,
            syncError: initialSyncError,
            message: sessionSuccessMessage
        }));
        openModal(true);
    } else if (showLeadFormModal) {
        renderModal(getModalCopy('lead-hub', {
            totalLeads: initialTotalLeads,
            syncError: initialSyncError,
            message: ''
        }));
        openModal(true);
    } else {
        closeModal();
    }
});
</script>
@endsection


