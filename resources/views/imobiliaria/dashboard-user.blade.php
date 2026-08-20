@extends('layout-inicial.Dashboard_User')

@section('content_w')
@php
    /*
    |--------------------------------------------------------------------------
    | Variáveis principais do dashboard
    |--------------------------------------------------------------------------
    */

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
    $leadSearch = $leadSearch ?? '';
    $isTagFiltered = filled($selectedTag);
    $isNameFiltered = filled($leadSearch);
    $isFiltering = $isTagFiltered || $isNameFiltered;
    $companyTagName = mb_strtolower(trim((string) ($company->name ?? '')));

     /*
    |--------------------------------------------------------------------------
    | Funções visuais para tags importantes
    |--------------------------------------------------------------------------
    | Essas funções ajudam a identificar visualmente leads aprovados e ruins
    | sem alterar a regra de negócio nem o banco de dados.
    */

    $normalizeTag = function ($value) {
        return \Illuminate\Support\Str::of((string) $value)
            ->ascii()
            ->lower()
            ->squish()
            ->toString();
    };

    /*
    |--------------------------------------------------------------------------
    | Define a cor de cada tag
    |--------------------------------------------------------------------------
    | aprovado/aprovados -> verde
    | ruim -> vermelho
    | demais -> neutro
    */
    $tagToneClass = function ($tag) use ($normalizeTag) {
        $normalizedTag = $normalizeTag($tag);

        return match (true) {
            str_contains($normalizedTag, 'aprovad') => 'dashboard-tag-chip--approved',
            str_contains($normalizedTag, 'ruim') => 'dashboard-tag-chip--bad',
            default => 'dashboard-tag-chip--neutral',
        };
    };

    /*
    |--------------------------------------------------------------------------
    | Define a aparência do card do lead
    |--------------------------------------------------------------------------
    | Se tiver tag "ruim", o vermelho tem prioridade.
    | Isso evita exibir como aprovado um lead que também esteja marcado como ruim.
    */
    $getLeadTone = function ($tags) use ($normalizeTag) {
        $normalizedTags = collect($tags)
            ->map(fn ($tag) => $normalizeTag($tag));

        if ($normalizedTags->contains(fn ($tag) => str_contains($tag, 'ruim'))) {
            return [
                'card' => 'lead-card--bad',
                'badge' => 'lead-quality-badge--bad',
                'label' => 'Lead ruim',
            ];
        }

        if ($normalizedTags->contains(fn ($tag) => str_contains($tag, 'aprovad'))) {
            return [
                'card' => 'lead-card--approved',
                'badge' => 'lead-quality-badge--approved',
                'label' => 'Aprovado',
            ];
        }

        return [
            'card' => 'lead-card--neutral',
            'badge' => null,
            'label' => null,
        ];
    };

    $currentStart = $leads->firstItem() ?? 0;
    $currentEnd = $leads->lastItem() ?? 0;

    /*
    |--------------------------------------------------------------------------
    | Nova lógica de acesso ao formulário
    |--------------------------------------------------------------------------
    | leadFormUrl deve apontar para:
    | route('simulation.registered-company.access')
    |
    | leadAccessCode é a chave curta da imobiliária.
    */

    $leadFormUrl = $leadFormUrl ?? null;
    $leadAccessCode = $leadAccessCode ?? null;
    $leadFormAvailable = filled($leadFormUrl);
    $leadAccessCodeAvailable = filled($leadAccessCode);

    $insuranceAnalysisEnabled = (bool) config(
        'features.insurance_analysis.enabled',
        false
    );

    $leadValidationFields = [
        'nome',
        'email',
        'tel',
        'cpf',
        'tipo_solicitante',
        'estado_civil',
        'conjuge_nome',
        'conjuge_cpf',
        'valor_aluguel',
        'valor_agua',
        'valor_luz',
        'valor_gas',
        'valor_condominio',
        'valor_iptu',
        'outras_despesas',
        'cep',
        'estado',
        'cidade_imovel',
        'bairro',
        'logradouro',
        'numero',
        'complemento',
    ];

    $leadContextId = (string) old('lead_context_id', '');
    $firstInvalidLeadField = collect($leadValidationFields)->first(
        fn (string $field): bool => $errors->has($field)
    );
    $leadValidationTargets = null;

    if (filled($leadContextId) && filled($firstInvalidLeadField)) {
        $contextLead = $leads->first(
            fn ($lead): bool => (string) $lead->id === $leadContextId
        );

        if ($contextLead) {
            $leadValidationTargets = [
                'modal' => 'leadModal'.$contextLead->id,
                'tab' => 'lead-data-tab-'.$contextLead->id,
                'field' => 'company-lead-'.$contextLead->id.'-'.str_replace('_', '-', $firstInvalidLeadField),
            ];
        }
    }

@endphp


<div id="dashboardThemeRoot" class="dashboard-shell" data-dashboard-theme="light">
    <div class="container-fluid px-3 px-lg-4 py-4">
        @if (session('success'))
            <div class="alert alert-success rounded-4 border-0 shadow-sm">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-warning rounded-4 border-0 shadow-sm">
                {{ session('error') }}
            </div>
        @endif

        @if (filled($firstInvalidLeadField) && $leadValidationTargets === null)
            <div class="alert alert-danger rounded-4 border-0 shadow-sm" role="alert">
                Não foi possível associar os erros ao lead exibido nesta página.
            </div>
        @endif

        {{-- Cabeçalho moderno --}}
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
            <div>
                <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle mb-2">
                    Dashboard da imobiliária
                </span>

                <h1 class="h2 fw-bold mb-1">
                    Central de leads e simulações
                </h1>

                <p class="text-muted mb-0">
                    Acompanhe os leads enviados pelos formulários do sistema e copie sua chave de acesso.
                </p>
            </div>

            <div class="d-flex flex-column flex-sm-row gap-2">
                <a
                    href="{{ $leadFormUrl ?? '#' }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="btn btn-outline-primary {{ $leadFormAvailable ? '' : 'disabled' }}"
                    @if (!$leadFormAvailable) aria-disabled="true" tabindex="-1" @endif
                >
                    Abrir simulação
                </a>

                <button type="button" class="btn btn-outline-secondary" id="dashboardThemeToggle">
                    Modo escuro
                </button>
            </div>
        </div>

        {{-- Bloco principal superior --}}
        <div class="row g-4 mb-4">

            {{-- Hero principal --}}
            <div class="col-12 col-xl-7">
                <div class="card border-0 shadow-sm rounded-5 dashboard-hero-card h-100 text-white">
                    <div class="card-body p-4 p-lg-5 position-relative">
                        <div class="row g-4 align-items-end">
                            <div class="col-12 col-lg-8">
                                <span class="badge bg-white text-primary mb-3">
                                    CRM operacional
                                </span>

                                <h2 class="display-6 fw-bold dashboard-hero-title mb-3">
                                    Leads organizados para atendimento rápido.
                                </h2>

                                <p class="text-white-50 mb-4">
                                    Use este painel para acompanhar entradas, filtrar origens e manter o processo comercial mais simples.
                                </p>

                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $totalLeads }} leads totais
                                    </span>

                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $recentLeads }} recentes
                                    </span>

                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $withPhone }} com telefone
                                    </span>
                                </div>
                            </div>

                            <div class="col-12 col-lg-4">
                                <div class="bg-white bg-opacity-10 rounded-4 p-3 border border-white border-opacity-25">
                                    <div class="small text-white-50 mb-1">
                                        Última entrada
                                    </div>

                                    <div class="fw-bold">
                                        {{ $latestLeadAt ? $latestLeadAt->format('d/m/Y H:i') : 'Sem leads cadastrados' }}
                                    </div>

                                    <hr class="border-white border-opacity-25">

                                    <div class="small text-white-50 mb-1">
                                        Origem exibida
                                    </div>

                                    <span class="badge text-bg-success">
                                        Formulários do sistema
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            {{-- Acesso rápido --}}
            <div class="col-12 col-xl-5">
                <div class="card border-0 shadow-sm rounded-5 h-100">
                    <div class="card-body p-4 p-lg-5">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <span class="badge text-bg-dark mb-2">
                                    Acesso rápido
                                </span>

                                <h2 class="h4 fw-bold mb-1">
                                    Chave da imobiliária
                                </h2>

                                <p class="text-muted small mb-0">
                                    Compartilhe a chave junto com o link da página de simulação.
                                </p>
                            </div>

                            <span class="badge {{ $leadAccessCodeAvailable ? 'text-bg-success' : 'text-bg-danger' }}">
                                {{ $leadAccessCodeAvailable ? 'Ativa' : 'Indisponível' }}
                            </span>
                        </div>

                        <label class="form-label small text-muted">
                            Chave de acesso
                        </label>

                        <div class="input-group input-group-lg mb-3">
                            <input
                                type="text"
                                class="form-control fw-bold text-uppercase access-code-box"
                                value="{{ $leadAccessCode ?? '' }}"
                                readonly
                                id="dashboardLeadAccessCode"
                                @disabled(!$leadAccessCodeAvailable)
                            >

                            <button
                                class="btn btn-primary"
                                type="button"
                                id="dashboardLeadAccessCodeCopyButton"
                                @disabled(!$leadAccessCodeAvailable)
                            >
                                Copiar
                            </button>
                        </div>

                        <label class="form-label small text-muted">
                            Página de simulação
                        </label>

                        <div class="input-group mb-3">
                            <input
                                type="text"
                                class="form-control"
                                value="{{ $leadFormUrl ?? '' }}"
                                readonly
                                id="dashboardLeadFormLink"
                                @disabled(!$leadFormAvailable)
                            >

                            <button
                                class="btn btn-outline-primary"
                                type="button"
                                id="dashboardLeadFormCopyButton"
                                @disabled(!$leadFormAvailable)
                            >
                                Copiar link
                            </button>
                        </div>

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <a
                                href="{{ $leadFormUrl ?? '#' }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-outline-dark {{ $leadFormAvailable ? '' : 'disabled' }}"
                                id="dashboardLeadFormOpenButton"
                                @if (!$leadFormAvailable) aria-disabled="true" tabindex="-1" @endif
                            >
                                Abrir página
                            </a>

                            <span id="dashboardLeadFormCopyStatus" class="small {{ $leadFormAvailable ? 'text-muted' : 'text-danger' }}">
                                {{ $leadFormAvailable ? 'Envie o link e a chave para quem for preencher.' : 'Página indisponível.' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

        </div>



        {{-- Métricas --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-primary-subtle text-primary">
                                Base
                            </span>
                            <span class="text-primary fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalLeads }}
                        </div>

                        <div class="text-muted small">
                            leads disponíveis
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-success-subtle text-success">
                                Novos
                            </span>
                            <span class="text-success fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $newLeads }}
                        </div>

                        <div class="text-muted small">
                            em fase inicial
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-info-subtle text-info">
                                Contato
                            </span>
                            <span class="text-info fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $withPhone }}
                        </div>

                        <div class="text-muted small">
                            com telefone
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-warning-subtle text-warning">
                                Recentes
                            </span>
                            <span class="text-warning fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $recentLeads }}
                        </div>

                        <div class="text-muted small">
                            últimos 7 dias
                        </div>
                    </div>
                </div>
            </div>
        </div>


        {{-- Cards operacionais superiores --}}
        <div class="row g-4 mb-4">

            {{-- Resumo operacional --}}
            <div class="col-12 col-xl-4">
                <div class="card border-0 shadow-sm rounded-5 h-100">
                    <div class="card-body p-4">
                        <span class="badge text-bg-dark mb-2">
                            Operação
                        </span>

                        <h2 class="h5 fw-bold mb-3">
                            Resumo da base
                        </h2>

                        <div class="vstack gap-3">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-3">
                                <div>
                                    <div class="fw-semibold">Com telefone</div>
                                    <div class="small text-muted">Prontos para contato direto</div>
                                </div>
                                <span class="badge text-bg-success rounded-pill">{{ $withPhone }}</span>
                            </div>

                            <div class="d-flex justify-content-between align-items-center border-bottom pb-3">
                                <div>
                                    <div class="fw-semibold">Sem telefone</div>
                                    <div class="small text-muted">Precisam de complemento</div>
                                </div>
                                <span class="badge text-bg-warning rounded-pill">{{ $withoutPhone }}</span>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold">Últimos 7 dias</div>
                                    <div class="small text-muted">Entradas recentes</div>
                                </div>
                                <span class="badge text-bg-primary rounded-pill">{{ $recentLeads }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Tags principais --}}
            <div class="col-12 col-xl-4">
                <div class="card border-0 shadow-sm rounded-5 h-100">
                    <div class="card-body p-4">
                        <span class="badge text-bg-secondary mb-2">
                            Segmentação
                        </span>

                        <h2 class="h5 fw-bold mb-3">
                            Tags com maior volume
                        </h2>

                        <div class="list-group list-group-flush">
                            @forelse ($topTags as $tag => $count)
                                <a
                                    href="{{ request()->fullUrlWithQuery(['tag' => $tag, 'page' => 1]) }}#leads-section"
                                    class="list-group-item list-group-item-action d-flex justify-content-between align-items-center px-0 {{ $selectedTag === $tag ? 'active px-3 rounded-3' : '' }}"
                                >
                                    <div>
                                        <div class="fw-semibold">{{ $tag }}</div>
                                        <div class="small {{ $selectedTag === $tag ? 'text-white-50' : 'text-muted' }}">
                                            Leads desta origem
                                        </div>
                                    </div>

                                    <span class="badge {{ $selectedTag === $tag ? 'text-bg-light text-primary' : 'text-bg-primary' }} rounded-pill">
                                        {{ $count }}
                                    </span>
                                </a>
                            @empty
                                <p class="text-muted small mb-0">
                                    As tags aparecerão aqui assim que existirem leads segmentados.
                                </p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>

            {{-- Ajuda rápida --}}
            <div class="col-12 col-xl-4">
                <div class="card border-0 shadow-sm rounded-5 h-100">
                    <div class="card-body p-4">
                        <span class="badge text-bg-info mb-2">
                            Guia rápido
                        </span>

                        <h2 class="h5 fw-bold mb-3">
                            Como usar este painel
                        </h2>

                        <div class="vstack gap-3">
                            <div class="d-flex gap-3">
                                <span class="badge rounded-circle text-bg-primary d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                                    1
                                </span>
                                <div>
                                    <div class="fw-semibold">Compartilhe a chave</div>
                                    <div class="small text-muted">Envie a chave e o link da simulação para sua equipe.</div>
                                </div>
                            </div>

                            <div class="d-flex gap-3">
                                <span class="badge rounded-circle text-bg-primary d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                                    2
                                </span>
                                <div>
                                    <div class="fw-semibold">Acompanhe novos leads</div>
                                    <div class="small text-muted">Os leads captados aparecerão nesta fila comercial.</div>
                                </div>
                            </div>

                            <div class="d-flex gap-3">
                                <span class="badge rounded-circle text-bg-primary d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                                    3
                                </span>
                                <div>
                                    <div class="fw-semibold">Filtre por tag ou nome</div>
                                    <div class="small text-muted">Use os filtros para localizar leads rapidamente.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        {{-- Área principal da lista de leads --}}
        <div class="row g-4">
            <div class="col-12">

                {{-- Filtros --}}
                <div class="card border-0 shadow-sm rounded-5 mb-4" id="leads-section">
                    <div class="card-body p-4">
                        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3">
                            <div>
                                <span class="badge text-bg-secondary mb-2">
                                    Filtros
                                </span>

                                <h2 class="h4 fw-bold mb-1">
                                    Fila comercial
                                </h2>

                                <p class="text-muted mb-0">
                                    @if ($isFiltering)
                                        {{ $filteredLeads }} lead(s) encontrados nos filtros atuais.
                                    @else
                                        {{ $totalLeads }} leads cadastrados na base.
                                    @endif
                                </p>
                            </div>

                            <form method="GET" action="{{ url()->current() }}#leads-section" class="row g-2 align-items-end">
                                {{-- Filtro por nome do lead --}}
                                <div class="col-12 col-lg-5">
                                    <label for="crm-lead-name-filter" class="form-label small text-muted">
                                        Buscar lead por nome
                                    </label>

                                    <input
                                        type="text"
                                        id="crm-lead-name-filter"
                                        name="lead_name"
                                        class="form-control"
                                        value="{{ $leadSearch }}"
                                        placeholder="Digite o primeiro nome ou nome completo"
                                        autocomplete="off"
                                    >
                                </div>

                                {{-- Filtro por tag --}}
                                <div class="col-12 col-lg-4">
                                    <label for="crm-tag-filter" class="form-label small text-muted">
                                        Filtrar por tag
                                    </label>

                                    <select id="crm-tag-filter" name="tag" class="form-select">
                                        <option value="">Todas as tags</option>

                                        @foreach ($filterTags as $tag => $count)
                                            <option value="{{ $tag }}" @selected($selectedTag === $tag)>
                                                {{ $tag }} ({{ $count }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                {{-- Ações --}}
                                <div class="col-12 col-lg-3 d-flex gap-2">
                                    <button class="btn btn-primary flex-fill" type="submit">
                                        Buscar
                                    </button>

                                    @if ($isFiltering)
                                        <a href="{{ url()->current() }}#leads-section" class="btn btn-outline-secondary">
                                            Limpar
                                        </a>
                                    @endif
                                </div>
                            </form>
                        </div>

                        @if ($filterTags->isNotEmpty())
                            <div class="d-flex flex-wrap gap-2 mt-4">
                                @foreach ($filterTags->take(10) as $tag => $count)
                                    @php
                                        $filterChipClass = $tagToneClass($tag);
                                        $isSelectedFilterChip = $selectedTag === $tag;
                                    @endphp

                                    <a
                                        href="{{ request()->fullUrlWithQuery(['tag' => $tag, 'page' => 1]) }}#leads-section"
                                        class="badge rounded-pill text-decoration-none px-3 py-2 dashboard-filter-chip {{ $filterChipClass }} {{ $isSelectedFilterChip ? 'dashboard-tag-chip--selected' : '' }}"
                                    >
                                        {{ $tag }} · {{ $count }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Lista de leads em largura total --}}
                @if ($leads->total() > 0)
                    <div class="lead-list vstack gap-3">
                        @foreach ($leads as $lead)
                            @php
                                $leadName = $lead->nome ?: 'Lead sem nome';
                                $leadEmail = $lead->email ?: 'E-mail não informado';
                                $leadPhone = $lead->tel ?: 'Telefone não informado';
                                $leadCity = $lead->endereco?->cidade_imovel ?? 'Cidade não informada';

                                $leadDate = $lead->created_at ? $lead->created_at->format('d/m/Y') : 'Sem data';
                                $leadTime = $lead->created_at ? $lead->created_at->format('H:i') : '--:--';

                                $statusKey = \Illuminate\Support\Str::slug($lead->status ?: 'novo');
                                $statusLabel = $statusLabels[$statusKey] ?? ucfirst(str_replace('-', ' ', $statusKey));

                                $allTags = collect(preg_split('/\s*,\s*/', $lead->tags_originais ?? ''))
                                    ->filter(fn ($tag) => filled($tag))
                                    ->map(fn ($tag) => trim($tag))
                                    ->reject(function ($tag) use ($companyTagName) {
                                        return mb_strtolower(trim($tag)) === $companyTagName;
                                    });

                                $leadTone = $getLeadTone($allTags);
                                $visibleTags = $allTags->take(3);
                                $remainingTags = max($allTags->count() - $visibleTags->count(), 0);
                                $officialLeadTag = $allTags->first(function ($tag) use ($normalizeTag) {
                                        return str_contains($normalizeTag($tag), 'ruim');
                                    });

                                    if (!$officialLeadTag) {
                                        $officialLeadTag = $allTags->first(function ($tag) use ($normalizeTag) {
                                            return str_contains($normalizeTag($tag), 'aprovad');
                                        });
                                    }

                                $officialLeadTag = $officialLeadTag ?: $allTags->first();

                                $leadInitials = collect(preg_split('/\s+/', trim($leadName)))
                                    ->filter()
                                    ->take(2)
                                    ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
                                    ->implode('');

                                $statusBadge = match ($statusKey) {
                                    'novo' => 'text-bg-primary',
                                    'em-andamento' => 'text-bg-warning',
                                    'qualificado' => 'text-bg-info',
                                    'convertido' => 'text-bg-success',
                                    'perdido' => 'text-bg-danger',
                                    default => 'text-bg-secondary',
                                };
                            @endphp

                            <article class="card border-0 shadow-sm rounded-5 lead-card lead-list-item {{ $leadTone['card'] }}">
                                <div class="card-body p-3 p-lg-4">

                                    <div class="row g-3 align-items-center">

                                        {{-- Identificação --}}
                                        <div class="col-12 col-md-6 col-xl-3">
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="lead-avatar rounded-4 bg-primary text-white d-flex align-items-center justify-content-center fw-bold">
                                                    {{ $leadInitials ?: 'L' }}
                                                </div>

                                                <div class="min-w-0">
                                                    <h3 class="h6 fw-bold mb-1 text-truncate">
                                                        {{ $leadName }}
                                                    </h3>

                                                    <div class="small text-muted text-truncate">
                                                        {{ $leadCity }}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- E-mail --}}
                                        <div class="col-12 col-md-6 col-xl-3">
                                            <div class="small text-muted">E-mail</div>

                                            @if ($lead->email)
                                                <a href="mailto:{{ $lead->email }}" class="fw-semibold text-decoration-none text-truncate d-block">
                                                    {{ $leadEmail }}
                                                </a>
                                            @else
                                                <span class="fw-semibold text-truncate d-block">
                                                    {{ $leadEmail }}
                                                </span>
                                            @endif
                                        </div>

                                        {{-- Telefone --}}
                                        <div class="col-6 col-md-3 col-xl-2">
                                            <div class="small text-muted">Telefone</div>

                                            <div class="fw-semibold text-truncate">
                                                {{ $leadPhone }}
                                            </div>
                                        </div>

                                        {{-- Entrada --}}
                                        <div class="col-6 col-md-3 col-xl-1">
                                            <div class="small text-muted">Entrada</div>

                                            <div class="fw-semibold">
                                                {{ $leadDate }}
                                            </div>

                                            <div class="small text-muted">
                                                {{ $leadTime }}
                                            </div>
                                        </div>

                                        {{-- Tag oficial do lead --}}
                                        <div class="col-12 col-md-4 col-xl-2">
                                            <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-xl-end">

                                                @if ($officialLeadTag)
                                                    @php
                                                        $officialTagChipClass = $tagToneClass($officialLeadTag);
                                                        $isSelectedOfficialTag = $selectedTag === $officialLeadTag;
                                                    @endphp

                                                    <a
                                                        href="{{ request()->fullUrlWithQuery(['tag' => $officialLeadTag, 'page' => 1]) }}#leads-section"
                                                        class="badge rounded-pill text-decoration-none dashboard-tag-chip {{ $officialTagChipClass }} {{ $isSelectedOfficialTag ? 'dashboard-tag-chip--selected' : '' }}"
                                                    >
                                                        {{ $officialLeadTag }}
                                                    </a>
                                                @else
                                                    <span class="badge rounded-pill text-bg-light border text-muted dashboard-tag-chip">
                                                        Sem tag
                                                    </span>
                                                @endif

                                            </div>
                                        </div>

                                        {{-- Botão --}}
                                        <div class="col-12 col-md-2 col-xl-1">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary w-100 text-nowrap"
                                                data-bs-toggle="modal"
                                                data-bs-target="#leadModal{{ $lead->id }}"
                                            >
                                                Visualizar
                                            </button>
                                        </div>

                                    </div>

                                    <div class="d-flex flex-wrap align-items-center gap-2 mt-3 pt-3 border-top">
                                        <span class="small text-muted">
                                            LeadLovers:
                                        </span>
                                        @include('partials.leadlovers-sync-status', ['lead' => $lead])
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="card border-0 shadow-sm rounded-4 mt-4">
                        <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <p class="text-muted small mb-0">
                                Exibindo {{ $currentStart }} a {{ $currentEnd }} de {{ $filteredLeads }} leads{{ $isFiltering ? ' filtrados' : '' }}.
                            </p>

                            @if ($leads->hasPages())
                                <div>
                                    {{ $leads->onEachSide(1)->links('pagination::bootstrap-5') }}
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="card border-0 shadow-sm rounded-5">
                        <div class="card-body text-center p-5">
                            <span class="badge text-bg-light border mb-3">
                                Base vazia
                            </span>

                            @if ($isFiltering)
                                <h3 class="h5 fw-bold">
                                    Nenhum lead encontrado com os filtros informados.
                                </h3>

                                <p class="text-muted">
                                    Tente pesquisar outro nome, escolher outra tag ou limpar os filtros.
                                </p>

                                <a href="{{ url()->current() }}#leads-section" class="btn btn-outline-secondary">
                                    Limpar filtros
                                </a>
                            @else
                                <h3 class="h5 fw-bold">
                                    Nenhum lead encontrado.
                                </h3>

                                <p class="text-muted">
                                    Assim que novos contatos forem enviados pelos formulários, eles aparecerão aqui.
                                </p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>


{{-- Modais --}}
@foreach ($leads as $lead)
@php
    $leadName = $lead->nome ?: 'Lead sem nome';

    $statusKey = \Illuminate\Support\Str::slug($lead->status ?: 'novo');
    $statusLabel = $statusLabels[$statusKey] ?? ucfirst(str_replace('-', ' ', $statusKey));

    $statusBadge = match ($statusKey) {
        'novo' => 'text-bg-primary',
        'em-andamento' => 'text-bg-warning',
        'qualificado' => 'text-bg-info',
        'convertido' => 'text-bg-success',
        'perdido' => 'text-bg-danger',
            default => 'text-bg-secondary',
    };

    $allTags = collect(preg_split('/\s*,\s*/', $lead->tags_originais ?? ''))
        ->filter(fn ($tag) => filled($tag))
        ->map(fn ($tag) => trim($tag))
        ->reject(function ($tag) use ($companyTagName) {
            return mb_strtolower(trim($tag)) === $companyTagName;
        });

    $lastAnalysis = $insuranceAnalysisEnabled
        ? $lead->insuranceAnalyses()
            ->latest('created_at')
            ->first()
        : null;

    $lastLeadUpdate = collect([
        $lead->updated_at,
        optional($lead->endereco)->updated_at,
    ])->filter()->max();

    $canReanalyze = $insuranceAnalysisEnabled
        && $lead->canRequestReanalysis();

    $isLeadValidationContext = filled($firstInvalidLeadField)
        && $leadContextId === (string) $lead->id;
@endphp
<div
    class="modal fade lead-details-modal"
    id="leadModal{{ $lead->id }}"
    tabindex="-1"
    aria-labelledby="leadModalLabel{{ $lead->id }}"
    aria-hidden="true"
>
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content rounded-5 border-0 shadow-lg">

            <div class="modal-header border-0 pb-0">
                <div>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <span class="badge {{ $statusBadge }}">
                            {{ $statusLabel }}
                        </span>
                        @include('partials.leadlovers-sync-status', ['lead' => $lead])
                    </div>

                    <h5 class="modal-title fw-bold" id="leadModalLabel{{ $lead->id }}">
                        {{ $leadName }}
                    </h5>

                    <p class="text-muted small mb-0">
                        Entrada em {{ $lead->created_at ? $lead->created_at->format('d/m/Y H:i') : 'data não informada' }}
                    </p>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body p-4">

                <ul class="nav nav-pills mb-4" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button
                            id="lead-data-tab-{{ $lead->id }}"
                            class="nav-link active"
                            data-bs-toggle="pill"
                            data-bs-target="#lead-data-pane-{{ $lead->id }}"
                            type="button"
                            role="tab"
                            aria-controls="lead-data-pane-{{ $lead->id }}"
                            aria-selected="true"
                        >
                            Dados do lead
                        </button>
                    </li>

                    <li class="nav-item" role="presentation">
                        <button
                            id="lead-tags-tab-{{ $lead->id }}"
                            class="nav-link"
                            data-bs-toggle="pill"
                            data-bs-target="#lead-tags-pane-{{ $lead->id }}"
                            type="button"
                            role="tab"
                            aria-controls="lead-tags-pane-{{ $lead->id }}"
                            aria-selected="false"
                        >
                            Tags
                        </button>
                    </li>

                    @if ($insuranceAnalysisEnabled)
                        <li class="nav-item" role="presentation">
                            <button
                                id="lead-reanalysis-tab-{{ $lead->id }}"
                                class="nav-link"
                                data-bs-toggle="pill"
                                data-bs-target="#lead-reanalysis-pane-{{ $lead->id }}"
                                type="button"
                                role="tab"
                                aria-controls="lead-reanalysis-pane-{{ $lead->id }}"
                                aria-selected="false"
                            >
                                Reanálise
                            </button>
                        </li>
                    @endif
                </ul>

                <div class="tab-content">

                    {{-- Aba 1: dados editáveis --}}
                    <div
                        class="tab-pane fade show active"
                        id="lead-data-pane-{{ $lead->id }}"
                        role="tabpanel"
                        aria-labelledby="lead-data-tab-{{ $lead->id }}"
                    >
                        <form
                            method="POST"
                            action="{{ route('dashboard.leads.update', $lead) }}"
                            id="leadUpdateForm{{ $lead->id }}"
                            class="lead-update-form"
                            data-lead-id="{{ $lead->id }}"
                            data-lead-tab-id="lead-data-tab-{{ $lead->id }}"
                        >
                            @csrf
                            @method('PUT')

                            @include('partials.leadlovers-sync-status', [
                                'lead' => $lead,
                                'showLeadLoversBadge' => false,
                                'showLeadLoversFailureMessage' => true,
                            ])

                            <div class="mt-3">
                                @include('partials.lead-update-fields', [
                                    'lead' => $lead,
                                    'leadUpdateIdPrefix' => 'company-lead',
                                    'isLeadValidationContext' => $isLeadValidationContext,
                                ])
                            </div>
                        </form>
                    </div>

                    {{-- Aba 2: tags somente leitura --}}
                    <div
                        class="tab-pane fade"
                        id="lead-tags-pane-{{ $lead->id }}"
                        role="tabpanel"
                        aria-labelledby="lead-tags-tab-{{ $lead->id }}"
                    >
                        <div class="card border rounded-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                    <div>
                                        <h6 class="fw-bold mb-1">
                                            Tags do lead
                                        </h6>

                                        <p class="text-muted small mb-0">
                                            As tags são exibidas apenas para consulta. A alteração de tags será feita pelo corretor no painel administrativo.
                                        </p>
                                    </div>

                                    <span class="badge text-bg-secondary">
                                        Somente leitura
                                    </span>
                                </div>

                                <div class="d-flex flex-wrap gap-2">
                                    @forelse ($allTags as $tag)
                                        <span class="badge rounded-pill dashboard-tag-chip {{ $tagToneClass($tag) }}">
                                            {{ $tag }}
                                        </span>
                                    @empty
                                        <span class="text-muted small">
                                            Nenhuma tag operacional cadastrada para este lead.
                                        </span>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>

                    @if ($insuranceAnalysisEnabled)
                        {{-- Aba 3: reanálise --}}
                        <div
                            class="tab-pane fade"
                            id="lead-reanalysis-pane-{{ $lead->id }}"
                            role="tabpanel"
                            aria-labelledby="lead-reanalysis-tab-{{ $lead->id }}"
                        >
                        @if ($canReanalyze)
                            <div class="alert alert-success rounded-4">
                                <strong>Reanálise liberada.</strong>
                                Este lead possui alterações salvas depois da última análise.
                            </div>

                            <form
                                method="POST"
                                action="{{ route('dashboard.leads.reanalyze', $lead) }}"
                                id="leadReanalyzeForm{{ $lead->id }}"
                            >
                                @csrf

                                <button type="submit" class="btn btn-warning">
                                    Solicitar reanálise
                                </button>
                            </form>
                        @else
                            <div class="alert alert-info rounded-4">
                                <strong>Reanálise bloqueada.</strong>
                                Para solicitar uma nova análise, altere algum dado do lead e clique em
                                <strong>Salvar alterações</strong>.
                            </div>

                            @if ($lastAnalysis)
                                <p class="text-muted small mb-0">
                                    Última análise registrada em:
                                    {{ $lastAnalysis->created_at->format('d/m/Y H:i') }}
                                </p>
                            @else
                                <p class="text-muted small mb-0">
                                    Nenhuma análise anterior foi encontrada para este lead.
                                </p>
                            @endif
                        @endif
                        </div>
                    @endif
                </div>
            </div>
            

            <div class="modal-footer border-0 pt-0">
                <button
                    type="submit"
                    form="leadUpdateForm{{ $lead->id }}"
                    class="btn btn-primary"
                    data-lead-submit
                    data-lead-id="{{ $lead->id }}"
                >
                    <span
                        class="spinner-border spinner-border-sm me-2 d-none"
                        aria-hidden="true"
                        data-lead-spinner
                    ></span>
                    <span data-lead-submit-label>Salvar dados</span>
                </button>

                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Fechar
                </button>
            </div>
        </div>
    </div>
</div>
@endforeach

<script id="dashboardUserConfig" type="application/json">
    {!! json_encode([
        'leadFormUrl' => $leadFormUrl,
        'leadAccessCode' => $leadAccessCode,
        'leadValidationTargets' => $leadValidationTargets,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>

@endsection
