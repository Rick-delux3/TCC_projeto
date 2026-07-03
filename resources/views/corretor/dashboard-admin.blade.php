@extends('layout-inicial.dashboard_Admin')

@section('content_a')
@php
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Str;

    /*
    |--------------------------------------------------------------------------
    | Corretor/admin logado
    |--------------------------------------------------------------------------
    */

    $corretor = $corretor ?? auth('admin')->user();

    $isCeo = $corretor && method_exists($corretor, 'isCeo')
        ? $corretor->isCeo()
        : (($corretor->role ?? null) === 'CEO');

    /*
    |--------------------------------------------------------------------------
    | Estatísticas focadas no dashboard principal
    |--------------------------------------------------------------------------
    | O dashboard principal não exibe equipe nem tela de análises.
    | Ele mostra a base geral de clientes/leads das imobiliárias.
    */

    $dashboardStats = $dashboardStats ?? [];

    $totalLeads = $dashboardStats['totalLeads'] ?? 0;
    $newLeads = $dashboardStats['newLeads'] ?? 0;
    $recentLeads = $dashboardStats['recentLeads'] ?? 0;
    $totalImobiliarias = $dashboardStats['totalImobiliarias'] ?? 0;

    $totalAprovados = $dashboardStats['totalAprovados'] ?? 0;
    $totalRecusados = $dashboardStats['totalRecusados'] ?? 0;

    $latestLeadAt = $dashboardStats['latestLeadAt'] ?? null;

    /*
    |--------------------------------------------------------------------------
    | Dados da lista de clientes/leads
    |--------------------------------------------------------------------------
    */

    $leads = $leads ?? collect();
    $imobiliarias = $imobiliarias ?? collect();

    $leadSearch = $leadSearch ?? request('lead_name', '');
    $selectedImobiliaria = $selectedImobiliaria ?? request('imobiliaria', '');
    $selectedResultado = $selectedResultado ?? request('resultado', '');

    $filteredLeads = method_exists($leads, 'total')
        ? $leads->total()
        : $leads->count();

    $currentStart = method_exists($leads, 'firstItem')
        ? ($leads->firstItem() ?? 0)
        : 0;

    $currentEnd = method_exists($leads, 'lastItem')
        ? ($leads->lastItem() ?? 0)
        : $filteredLeads;

    $isFiltering = filled($leadSearch)
        || filled($selectedImobiliaria)
        || filled($selectedResultado);

    /*
    |--------------------------------------------------------------------------
    | Rotas
    |--------------------------------------------------------------------------
    */

    $dashboardRoute = Route::has('Dashboard-Admin')
        ? route('Dashboard-Admin')
        : url()->current();

    /*
    |--------------------------------------------------------------------------
    | A rota de análises fica separada.
    | Use a rota real do seu projeto quando ajustar o controller de análises admin.
    |--------------------------------------------------------------------------
    */

    $analisesRoute = Route::has('admin.insurance-analyses.index')
        ? route('admin.insurance-analyses.index')
        : (Route::has('insurance-analyses.index') ? route('insurance-analyses.index') : '#');

    $solicitarAnaliseRoute = function ($lead) {
        if (Route::has('admin.insurance-analyses.create')) {
            return route('admin.insurance-analyses.create', ['lead' => $lead->id]);
        }

        if (Route::has('insurance-analyses.create')) {
            return route('insurance-analyses.create', ['lead' => $lead->id]);
        }

        if (Route::has('dashboard.leads.reanalyze')) {
            return route('dashboard.leads.reanalyze', $lead);
        }

        return '#';
    };

    /*
    |--------------------------------------------------------------------------
    | Labels e funções visuais
    |--------------------------------------------------------------------------
    */

    $resultadoLabels = [
        'aprovado' => 'Aprovados',
        'recusado' => 'Recusados/Reprovados',
    ];

    $selectedImobiliariaModel = filled($selectedImobiliaria)
        ? $imobiliarias->firstWhere('id', (int) $selectedImobiliaria)
        : null;

    $selectedImobiliariaName = $selectedImobiliariaModel?->name
        ?? $selectedImobiliariaModel?->nome
        ?? null;

    $normalizeTag = function ($value) {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->squish()
            ->toString();
    };

    $getLeadResultTone = function ($tags) use ($normalizeTag) {
        $normalizedTags = collect($tags)
            ->map(fn ($tag) => $normalizeTag($tag));

        if ($normalizedTags->contains(fn ($tag) =>
            str_contains($tag, 'recusad')
            || str_contains($tag, 'reprovad')
            || str_contains($tag, 'ruim')
        )) {
            return [
                'label' => 'Recusado',
                'badge' => 'text-bg-danger',
                'card' => 'lead-card--bad',
                'icon' => 'bi-x-circle',
            ];
        }

        if ($normalizedTags->contains(fn ($tag) => str_contains($tag, 'aprovad'))) {
            return [
                'label' => 'Aprovado',
                'badge' => 'text-bg-success',
                'card' => 'lead-card--approved',
                'icon' => 'bi-check-circle',
            ];
        }

        return [
            'label' => 'Sem resultado',
            'badge' => 'text-bg-secondary',
            'card' => 'lead-card--neutral',
            'icon' => 'bi-dash-circle',
        ];
    };

    $getImobiliariaName = function ($lead) {
        return $lead->company?->name
            ?? $lead->company?->nome
            ?? $lead->imobiliaria?->name
            ?? $lead->imobiliaria?->nome
            ?? 'Não vinculada';
    };

    $getLeadCity = function ($lead) {
        return $lead->endereco?->cidade_imovel
            ?? $lead->cidade_imovel
            ?? 'Cidade não informada';
    };
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

        {{-- Cabeçalho do conteúdo --}}
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
            <div>
                <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle mb-2">
                    {{ $isCeo ? 'Dashboard do CEO' : 'Dashboard do corretor' }}
                </span>

                <h1 class="h2 fw-bold mb-1">
                    Clientes das imobiliárias
                </h1>

                <p class="text-muted mb-0">
                    Visualize a base geral de clientes, filtre por imobiliária e refine por aprovação ou recusa.
                </p>
            </div>

            <div class="d-flex flex-column flex-sm-row gap-2">
                @can('view-analyses')
                    <a href="{{ $analisesRoute }}" class="btn btn-outline-primary">
                        <i class="bi bi-clipboard2-data me-1"></i>
                        Visualizar análises
                    </a>
                @endcan

                <button type="button" class="btn btn-outline-secondary" id="dashboardThemeToggle">
                    Modo escuro
                </button>
            </div>
        </div>

        {{-- Hero principal --}}
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-5 dashboard-hero-card text-white">
                    <div class="card-body p-4 p-lg-5">
                        <div class="row g-4 align-items-end">
                            <div class="col-12 col-lg-8">
                                <span class="badge bg-white text-primary mb-3">
                                    Visão geral
                                </span>

                                <h2 class="display-6 fw-bold dashboard-hero-title mb-3">
                                    Base geral de clientes da corretora.
                                </h2>

                                <p class="text-white-50 mb-4">
                                    Acompanhe os clientes enviados pelas imobiliárias, filtre por origem e identifique rapidamente aprovados e recusados.
                                </p>

                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $totalLeads }} clientes/leads
                                    </span>

                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $totalImobiliarias }} imobiliárias
                                    </span>

                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $recentLeads }} recentes
                                    </span>
                                </div>
                            </div>

                            <div class="col-12 col-lg-4">
                                <div class="bg-white bg-opacity-10 rounded-4 p-3 border border-white border-opacity-25">
                                    <div class="small text-white-50 mb-1">
                                        Última entrada
                                    </div>

                                    <div class="fw-bold">
                                        {{ $latestLeadAt ? $latestLeadAt->format('d/m/Y H:i') : 'Sem clientes cadastrados' }}
                                    </div>

                                    <hr class="border-white border-opacity-25">

                                    <div class="small text-white-50 mb-1">
                                        Perfil de acesso
                                    </div>

                                    <span class="badge {{ $isCeo ? 'text-bg-light text-primary' : 'text-bg-secondary' }}">
                                        {{ $corretor->role ?? 'integrante' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Métricas focadas somente em clientes --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-primary-subtle text-primary">
                                Clientes
                            </span>
                            <span class="text-primary fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalLeads }}
                        </div>

                        <div class="text-muted small">
                            leads na base geral
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-success-subtle text-success">
                                Aprovados
                            </span>
                            <span class="text-success fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalAprovados }}
                        </div>

                        <div class="text-muted small">
                            clientes aprovados
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-danger-subtle text-danger">
                                Recusados
                            </span>
                            <span class="text-danger fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalRecusados }}
                        </div>

                        <div class="text-muted small">
                            recusados ou reprovados
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-info-subtle text-info">
                                Imobiliárias
                            </span>
                            <span class="text-info fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalImobiliarias }}
                        </div>

                        <div class="text-muted small">
                            parceiras cadastradas
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Filtros --}}
        <div class="card border-0 shadow-sm rounded-5 mb-4" id="leads-section">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
                    <div>
                        <span class="badge text-bg-secondary mb-2">
                            Filtros
                        </span>

                        <h2 class="h4 fw-bold mb-1">
                            Lista de clientes
                        </h2>

                        <p class="text-muted mb-0">
                            Filtre primeiro por imobiliária e depois por resultado, sem perder o filtro anterior.
                        </p>
                    </div>

                    <div class="text-xl-end">
                        <div class="fw-bold">
                            {{ $filteredLeads }} cliente(s)
                        </div>

                        <div class="small text-muted">
                            {{ $isFiltering ? 'Resultado filtrado' : 'Base geral' }}
                        </div>
                    </div>
                </div>

                @can('view-leads')
                    <form method="GET" action="{{ $dashboardRoute }}#leads-section">
                        <div class="row g-3 align-items-end">

                            {{-- Busca --}}
                            <div class="col-12 col-lg-4">
                                <label for="admin-lead-search" class="form-label small text-muted">
                                    Buscar cliente
                                </label>

                                <div class="input-group">
                                    <span class="input-group-text bg-white">
                                        <i class="bi bi-search"></i>
                                    </span>

                                    <input
                                        type="text"
                                        id="admin-lead-search"
                                        name="lead_name"
                                        class="form-control"
                                        value="{{ $leadSearch }}"
                                        placeholder="Nome, e-mail, CPF ou telefone"
                                        autocomplete="off"
                                    >
                                </div>
                            </div>

                            {{-- Imobiliária --}}
                            <div class="col-12 col-lg-4">
                                <label for="admin-imobiliaria-filter" class="form-label small text-muted">
                                    Imobiliária
                                </label>

                                <select id="admin-imobiliaria-filter" name="imobiliaria" class="form-select">
                                    <option value="">Todas as imobiliárias</option>

                                    @foreach ($imobiliarias as $imobiliaria)
                                        @php
                                            $imobiliariaName = $imobiliaria->name
                                                ?? $imobiliaria->nome
                                                ?? 'Imobiliária #' . $imobiliaria->id;
                                        @endphp

                                        <option
                                            value="{{ $imobiliaria->id }}"
                                            @selected((string) $selectedImobiliaria === (string) $imobiliaria->id)
                                        >
                                            {{ $imobiliariaName }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Resultado --}}
                            <div class="col-12 col-lg-3">
                                <label for="admin-resultado-filter" class="form-label small text-muted">
                                    Resultado/tag
                                </label>

                                <select id="admin-resultado-filter" name="resultado" class="form-select">
                                    <option value="">Todos</option>
                                    <option value="aprovado" @selected($selectedResultado === 'aprovado')>
                                        Aprovados
                                    </option>
                                    <option value="recusado" @selected($selectedResultado === 'recusado')>
                                        Recusados/Reprovados
                                    </option>
                                </select>
                            </div>

                            {{-- Botão --}}
                            <div class="col-12 col-lg-1 d-grid">
                                <button type="submit" class="btn btn-primary" title="Filtrar">
                                    <i class="bi bi-funnel"></i>
                                </button>
                            </div>
                        </div>
                    </form>

                    {{-- Filtros ativos --}}
                    @if ($isFiltering)
                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <span class="badge rounded-pill text-bg-light border text-muted px-3 py-2">
                                Filtros ativos:
                            </span>

                            @if (filled($leadSearch))
                                <a
                                    href="{{ request()->fullUrlWithQuery(['lead_name' => null, 'page' => 1]) }}#leads-section"
                                    class="badge rounded-pill text-bg-primary text-decoration-none px-3 py-2"
                                >
                                    Busca: {{ $leadSearch }}
                                    <i class="bi bi-x ms-1"></i>
                                </a>
                            @endif

                            @if (filled($selectedImobiliaria))
                                <a
                                    href="{{ request()->fullUrlWithQuery(['imobiliaria' => null, 'page' => 1]) }}#leads-section"
                                    class="badge rounded-pill text-bg-success text-decoration-none px-3 py-2"
                                >
                                    Imobiliária: {{ $selectedImobiliariaName ?? 'Selecionada' }}
                                    <i class="bi bi-x ms-1"></i>
                                </a>
                            @endif

                            @if (filled($selectedResultado))
                                <a
                                    href="{{ request()->fullUrlWithQuery(['resultado' => null, 'page' => 1]) }}#leads-section"
                                    class="badge rounded-pill text-bg-warning text-dark text-decoration-none px-3 py-2"
                                >
                                    Resultado: {{ $resultadoLabels[$selectedResultado] ?? $selectedResultado }}
                                    <i class="bi bi-x ms-1"></i>
                                </a>
                            @endif
                        </div>

                        <div class="mt-3">
                            <a href="{{ $dashboardRoute }}#leads-section" class="btn btn-sm btn-outline-secondary">
                                Limpar todos os filtros
                            </a>
                        </div>
                    @endif

                    {{-- Atalhos rápidos que preservam imobiliária e busca --}}
                    <div class="d-flex flex-wrap gap-2 mt-4 pt-3 border-top">
                        <span class="small text-muted me-1">
                            Refinar rapidamente:
                        </span>

                        <a
                            href="{{ request()->fullUrlWithQuery(['resultado' => null, 'page' => 1]) }}#leads-section"
                            class="badge rounded-pill text-decoration-none px-3 py-2 {{ blank($selectedResultado) ? 'text-bg-dark' : 'text-bg-light border text-muted' }}"
                        >
                            Todos
                        </a>

                        <a
                            href="{{ request()->fullUrlWithQuery(['resultado' => 'aprovado', 'page' => 1]) }}#leads-section"
                            class="badge rounded-pill text-decoration-none px-3 py-2 {{ $selectedResultado === 'aprovado' ? 'text-bg-success' : 'text-bg-light border text-success' }}"
                        >
                            Aprovados
                        </a>

                        <a
                            href="{{ request()->fullUrlWithQuery(['resultado' => 'recusado', 'page' => 1]) }}#leads-section"
                            class="badge rounded-pill text-decoration-none px-3 py-2 {{ $selectedResultado === 'recusado' ? 'text-bg-danger' : 'text-bg-light border text-danger' }}"
                        >
                            Recusados/Reprovados
                        </a>
                    </div>
                @else
                    <div class="alert alert-warning rounded-4 mb-0">
                        Você não possui permissão para visualizar leads.
                    </div>
                @endcan
            </div>
        </div>

        {{-- Lista de clientes --}}
        @can('view-leads')
            @if ($filteredLeads > 0)
                <div class="lead-list vstack gap-3">
                    @foreach ($leads as $lead)
                        @php
                            $leadName = $lead->nome ?: 'Cliente sem nome';
                            $leadEmail = $lead->email ?: 'E-mail não informado';
                            $leadPhone = $lead->tel ?: 'Telefone não informado';
                            $leadCity = $getLeadCity($lead);

                            $leadDate = $lead->created_at ? $lead->created_at->format('d/m/Y') : 'Sem data';
                            $leadTime = $lead->created_at ? $lead->created_at->format('H:i') : '--:--';

                            $allTags = collect(preg_split('/\s*,\s*/', $lead->tags_originais ?? ''))
                                ->filter(fn ($tag) => filled($tag))
                                ->map(fn ($tag) => trim($tag));

                            $resultTone = $getLeadResultTone($allTags);

                            $visibleTags = $allTags->take(3);
                            $remainingTags = max($allTags->count() - $visibleTags->count(), 0);

                            $leadInitials = collect(preg_split('/\s+/', trim($leadName)))
                                ->filter()
                                ->take(2)
                                ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                                ->implode('');

                            $imobiliariaName = $getImobiliariaName($lead);
                        @endphp

                        <article class="card border-0 shadow-sm rounded-5 lead-card lead-list-item {{ $resultTone['card'] }}">
                            <div class="card-body p-3 p-lg-4">
                                <div class="row g-3 align-items-center">

                                    {{-- Cliente --}}
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="lead-avatar rounded-4 bg-primary text-white d-flex align-items-center justify-content-center fw-bold">
                                                {{ $leadInitials ?: 'C' }}
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

                                    {{-- Imobiliária --}}
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <div class="small text-muted">
                                            Imobiliária
                                        </div>

                                        <div class="fw-semibold text-truncate">
                                            {{ $imobiliariaName }}
                                        </div>
                                    </div>

                                    {{-- E-mail --}}
                                    <div class="col-12 col-md-6 col-xl-2">
                                        <div class="small text-muted">
                                            E-mail
                                        </div>

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
                                    <div class="col-6 col-md-3 col-xl-1">
                                        <div class="small text-muted">
                                            Telefone
                                        </div>

                                        <div class="fw-semibold text-truncate">
                                            {{ $leadPhone }}
                                        </div>
                                    </div>

                                    {{-- Entrada --}}
                                    <div class="col-6 col-md-3 col-xl-1">
                                        <div class="small text-muted">
                                            Entrada
                                        </div>

                                        <div class="fw-semibold">
                                            {{ $leadDate }}
                                        </div>

                                        <div class="small text-muted">
                                            {{ $leadTime }}
                                        </div>
                                    </div>

                                    {{-- Resultado --}}
                                    <div class="col-6 col-md-4 col-xl-1">
                                        <div class="small text-muted">
                                            Resultado
                                        </div>

                                        <span class="badge {{ $resultTone['badge'] }}">
                                            <i class="bi {{ $resultTone['icon'] }} me-1"></i>
                                            {{ $resultTone['label'] }}
                                        </span>
                                    </div>

                                    {{-- Botões --}}
                                    <div class="col-12 col-md-4 col-xl-1">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary w-100 text-nowrap mb-2"
                                            data-bs-toggle="modal"
                                            data-bs-target="#adminLeadModal{{ $lead->id }}"
                                        >
                                            Visualizar
                                        </button>

                                        @can('create-analysis')
                                            @if ($solicitarAnaliseRoute($lead) !== '#')
                                                <form method="POST" action="{{ $solicitarAnaliseRoute($lead) }}">
                                                    @csrf

                                                    <button type="submit" class="btn btn-sm btn-warning w-100 text-nowrap">
                                                        Analisar
                                                    </button>
                                                </form>
                                            @else
                                                <button type="button" class="btn btn-sm btn-warning w-100 text-nowrap" disabled>
                                                    Analisar
                                                </button>
                                            @endif
                                        @endcan
                                    </div>
                                </div>

                                {{-- Tags visíveis --}}
                                @if ($visibleTags->isNotEmpty())
                                    <div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
                                        @foreach ($visibleTags as $tag)
                                            <span class="badge rounded-pill text-bg-light border text-muted">
                                                {{ $tag }}
                                            </span>
                                        @endforeach

                                        @if ($remainingTags > 0)
                                            <span class="badge rounded-pill text-bg-secondary">
                                                +{{ $remainingTags }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>

                {{-- Paginação --}}
                <div class="card border-0 shadow-sm rounded-4 mt-4">
                    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                        <p class="text-muted small mb-0">
                            Exibindo {{ $currentStart }} a {{ $currentEnd }} de {{ $filteredLeads }} cliente(s){{ $isFiltering ? ' filtrados' : '' }}.
                        </p>

                        @if (method_exists($leads, 'hasPages') && $leads->hasPages())
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
                            Nenhum cliente encontrado
                        </span>

                        @if ($isFiltering)
                            <h3 class="h5 fw-bold">
                                Nenhum cliente corresponde aos filtros atuais.
                            </h3>

                            <p class="text-muted">
                                Tente alterar a imobiliária, mudar o resultado ou limpar os filtros.
                            </p>

                            <a href="{{ $dashboardRoute }}#leads-section" class="btn btn-outline-secondary">
                                Limpar filtros
                            </a>
                        @else
                            <h3 class="h5 fw-bold">
                                Ainda não há clientes cadastrados.
                            </h3>

                            <p class="text-muted">
                                Assim que as imobiliárias enviarem simulações, os clientes aparecerão aqui.
                            </p>
                        @endif
                    </div>
                </div>
            @endif
        @endcan
    </div>
</div>


{{-- Modais dos clientes --}}
@can('view-leads')
    @foreach ($leads as $lead)
        @php
            $leadName = $lead->nome ?: 'Cliente sem nome';

            $imobiliariaName = $getImobiliariaName($lead);

            $allTags = collect(preg_split('/\s*,\s*/', $lead->tags_originais ?? ''))
                ->filter(fn ($tag) => filled($tag))
                ->map(fn ($tag) => trim($tag));

            $resultTone = $getLeadResultTone($allTags);
        @endphp

        <div
            class="modal fade lead-details-modal"
            id="adminLeadModal{{ $lead->id }}"
            tabindex="-1"
            aria-labelledby="adminLeadModalLabel{{ $lead->id }}"
            aria-hidden="true"
        >
            <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
                <div class="modal-content rounded-5 border-0 shadow-lg">

                    <div class="modal-header border-0 pb-0">
                        <div>
                            <span class="badge {{ $resultTone['badge'] }} mb-2">
                                {{ $resultTone['label'] }}
                            </span>

                            <h5 class="modal-title fw-bold" id="adminLeadModalLabel{{ $lead->id }}">
                                {{ $leadName }}
                            </h5>

                            <p class="text-muted small mb-0">
                                Imobiliária: {{ $imobiliariaName }}
                            </p>
                        </div>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>

                    <div class="modal-body p-4">

                        <ul class="nav nav-pills mb-4" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button
                                    class="nav-link active"
                                    data-bs-toggle="pill"
                                    data-bs-target="#admin-lead-data-pane-{{ $lead->id }}"
                                    type="button"
                                    role="tab"
                                >
                                    Dados do cliente
                                </button>
                            </li>

                            @can('view-tags')
                                <li class="nav-item" role="presentation">
                                    <button
                                        class="nav-link"
                                        data-bs-toggle="pill"
                                        data-bs-target="#admin-lead-tags-pane-{{ $lead->id }}"
                                        type="button"
                                        role="tab"
                                    >
                                        Tags
                                    </button>
                                </li>
                            @endcan
                        </ul>

                        <div class="tab-content">

                            {{-- Dados do cliente --}}
                            <div
                                class="tab-pane fade show active"
                                id="admin-lead-data-pane-{{ $lead->id }}"
                                role="tabpanel"
                            >
                                <div class="row g-4">
                                    <div class="col-12 col-lg-6">
                                        <div class="card border rounded-4 h-100">
                                            <div class="card-body">
                                                <h6 class="fw-bold mb-3">
                                                    Dados do solicitante
                                                </h6>

                                                <div class="vstack gap-2 small">
                                                    <div><strong>Nome:</strong> {{ $lead->nome ?? 'Não informado' }}</div>
                                                    <div><strong>E-mail:</strong> {{ $lead->email ?? 'Não informado' }}</div>
                                                    <div><strong>Telefone:</strong> {{ $lead->tel ?? 'Não informado' }}</div>
                                                    <div><strong>CPF/CNPJ:</strong> {{ $lead->cpf ?? 'Não informado' }}</div>
                                                    <div><strong>Tipo:</strong> {{ $lead->tipo_solicitante ?? 'Não informado' }}</div>
                                                    <div><strong>Status:</strong> {{ $lead->status ?? 'Não informado' }}</div>
                                                    <div><strong>Entrada:</strong> {{ $lead->created_at?->format('d/m/Y H:i') ?? 'Não informado' }}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 col-lg-6">
                                        <div class="card border rounded-4 h-100">
                                            <div class="card-body">
                                                <h6 class="fw-bold mb-3">
                                                    Endereço do imóvel
                                                </h6>

                                                <div class="vstack gap-2 small">
                                                    <div><strong>CEP:</strong> {{ $lead->endereco?->cep ?? 'Não informado' }}</div>
                                                    <div><strong>Estado:</strong> {{ $lead->endereco?->estado ?? 'Não informado' }}</div>
                                                    <div><strong>Cidade:</strong> {{ $lead->endereco?->cidade_imovel ?? 'Não informado' }}</div>
                                                    <div><strong>Bairro:</strong> {{ $lead->endereco?->bairro ?? 'Não informado' }}</div>
                                                    <div><strong>Logradouro:</strong> {{ $lead->endereco?->logradouro ?? 'Não informado' }}</div>
                                                    <div><strong>Número:</strong> {{ $lead->endereco?->numero ?? 'Não informado' }}</div>
                                                    <div><strong>Complemento:</strong> {{ $lead->endereco?->complemento ?? 'Não informado' }}</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="card border rounded-4">
                                            <div class="card-body">
                                                <h6 class="fw-bold mb-3">
                                                    Valores da locação
                                                </h6>

                                                <div class="row g-3 small">
                                                    <div class="col-6 col-md-3">
                                                        <strong>Aluguel:</strong><br>
                                                        R$ {{ number_format((float) ($lead->despesas?->valor_aluguel ?? 0), 2, ',', '.') }}
                                                    </div>

                                                    <div class="col-6 col-md-3">
                                                        <strong>Condomínio:</strong><br>
                                                        R$ {{ number_format((float) ($lead->despesas?->valor_condominio ?? 0), 2, ',', '.') }}
                                                    </div>

                                                    <div class="col-6 col-md-3">
                                                        <strong>IPTU:</strong><br>
                                                        R$ {{ number_format((float) ($lead->despesas?->valor_iptu ?? 0), 2, ',', '.') }}
                                                    </div>

                                                    <div class="col-6 col-md-3">
                                                        <strong>Total:</strong><br>
                                                        R$ {{ number_format((float) ($lead->despesas?->valor_total_encargos ?? 0), 2, ',', '.') }}
                                                    </div>
                                                </div>

                                                @can('create-analysis')
                                                    <hr>

                                                    @if ($solicitarAnaliseRoute($lead) !== '#')
                                                        <form method="POST" action="{{ $solicitarAnaliseRoute($lead) }}">
                                                            @csrf

                                                            <button type="submit" class="btn btn-warning">
                                                                <i class="bi bi-arrow-repeat me-1"></i>
                                                                Solicitar análise
                                                            </button>
                                                        </form>
                                                    @else
                                                        <button type="button" class="btn btn-warning" disabled>
                                                            Solicitar análise
                                                        </button>

                                                        <div class="small text-muted mt-2">
                                                            Rota de análise ainda não configurada.
                                                        </div>
                                                    @endif
                                                @endcan
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Tags --}}
                            @can('view-tags')
                                <div
                                    class="tab-pane fade"
                                    id="admin-lead-tags-pane-{{ $lead->id }}"
                                    role="tabpanel"
                                >
                                    <div class="card border rounded-4">
                                        <div class="card-body">
                                            <h6 class="fw-bold mb-1">
                                                Tags do cliente
                                            </h6>

                                            <p class="text-muted small">
                                                Tags usadas para identificar resultado, origem e segmentação.
                                            </p>

                                            <div class="d-flex flex-wrap gap-2">
                                                @forelse ($allTags as $tag)
                                                    <span class="badge rounded-pill text-bg-light border text-muted">
                                                        {{ $tag }}
                                                    </span>
                                                @empty
                                                    <span class="text-muted small">
                                                        Nenhuma tag encontrada para este cliente.
                                                    </span>
                                                @endforelse
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endcan
                        </div>
                    </div>

                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Fechar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
@endcan

@endsection