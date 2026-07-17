@extends('layout-inicial.dashboard_Admin')

@section('content_a')
@php
    use Illuminate\Support\Facades\Gate;
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

    $canCreateAnalysis = $canCreateAnalysis
        ?? (
            $corretor
                ? Gate::forUser($corretor)->allows('create-analysis')
                : false
        );

    $simulationCompanies = collect(
        $simulationCompanies
            ?? $imobiliarias
            ?? []
    )
        ->filter(
            fn ($company) => (bool) data_get(
                $company,
                'lead_form_active',
                true
            )
        )
        ->sortBy(
            fn ($company) => mb_strtolower(
                (string) data_get($company, 'name', '')
            )
        )
        ->values();

    $adminSimulationRouteExists = Route::has('admin.simulations.open');

    $adminSimulationOpenRoute = $adminSimulationRouteExists
        ? route('admin.simulations.open')
        : '#';

    $leadSearch = $leadSearch ?? request('lead_name', '');
    $selectedImobiliaria = $selectedImobiliaria ?? request('imobiliaria', '');
    $selectedResultado = $selectedResultado ?? request('resultado', '');
    $selectedTipoSolicitante = $selectedTipoSolicitante
        ?? request('tipo_solicitante', '');

    $tipoSolicitantesOptions = $tipoSolicitantesOptions ?? [
        'imobiliaria_cadastrada' => 'Imobiliária cadastrada',
        'imobiliaria_nao_cadastrada' => 'Imobiliária não cadastrada',
        'locador' => 'Proprietário / locador',
        'locatario' => 'Locatário',
    ];

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
        || filled($selectedResultado)
        || filled($selectedTipoSolicitante);

    /*
    |--------------------------------------------------------------------------
    | Rotas
    |--------------------------------------------------------------------------
    */

    $dashboardRoute = Route::has('Dashboard-Admin')
        ? route('Dashboard-Admin')
        : url()->current();

    $adminUpdateLeadRoute = function($lead) {
        return Route::has('admin.leads.update') ? route('admin.leads.update', $lead) : '#';
    };

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

        if (Route::has('admin.leads.reanalyze')) {
            return route('admin.leads.reanalyze', $lead);
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

    if ($selectedImobiliaria === 'sem_vinculo') {
        $selectedImobiliariaModel = null;
        $selectedImobiliariaName = 'Sem imobiliária vinculada';
    } else {
        $selectedImobiliariaModel = filled($selectedImobiliaria)
            ? $imobiliarias->firstWhere('id', (int) $selectedImobiliaria)
            : null;

        $selectedImobiliariaName = $selectedImobiliariaModel?->name
            ?? $selectedImobiliariaModel?->nome
            ?? null;
    }

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
        if ($lead->company_id) {
            return $lead->imobiliariaVinculada?->name
                ?? $lead->imobiliariaVinculada?->nome
                ?? (is_string($lead->imobiliaria) ? $lead->imobiliaria : null)
                ?? 'Imobiliária vinculada';
        }

        if ($lead->tipo_solicitante === 'imobiliaria_nao_cadastrada') {
            return $lead->imobiliariaInformada?->nome_imobiliaria_informada
                ?? (is_string($lead->imobiliaria) ? $lead->imobiliaria : null)
                ?? 'Imobiliária não cadastrada';
        }

        return 'Sem imobiliária vinculada';
    };

    $getTipoSolicitanteLabel = function ($lead) use ($tipoSolicitantesOptions) {
        return $tipoSolicitantesOptions[$lead->tipo_solicitante]
            ?? 'Perfil não informado';
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
                    Central de leads
                </h1>

                <p class="text-muted mb-0">
                    Visualize leads de imobiliárias cadastradas e solicitações públicas, filtrando por vínculo, perfil e resultado.
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
            <div class="col-12 {{ $canCreateAnalysis ? 'col-xl-7' : '' }}">
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

            @if ($canCreateAnalysis)
                <div class="col-12 col-xl-5">
                    <div class="card border-0 shadow-sm rounded-5 h-100 dashboard-stat-card">
                        <div class="card-body p-4 p-lg-5 d-flex flex-column">
                            <div>
                                <span class="badge text-bg-primary-subtle text-primary mb-3">
                                    Acesso rápido
                                </span>

                                <h2 class="h3 fw-bold mb-3">
                                    Nova solicitação de análise
                                </h2>

                                <p class="text-muted mb-3">
                                    Escolha o vínculo e abra o formulário adequado para solicitar uma nova análise.
                                </p>

                                <p class="small text-muted mb-4">
                                    <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
                                    O corretor não precisa digitar nem visualizar a chave de acesso da imobiliária.
                                </p>
                            </div>

                            <div class="mt-auto">
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#adminSimulationModal"
                                    @disabled(! $adminSimulationRouteExists)
                                >
                                    <i class="bi bi-clipboard-plus me-1" aria-hidden="true"></i>
                                    {{ $adminSimulationRouteExists ? 'Solicitar análise' : 'Solicitação indisponível' }}
                                </button>

                                @unless ($adminSimulationRouteExists)
                                    <div class="form-text">
                                        Recurso temporariamente indisponível.
                                    </div>
                                @endunless
                            </div>
                        </div>
                    </div>
                </div>
            @endif
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
                            Lista de leads
                        </h2>

                        <p class="text-muted mb-0">
                            Combine busca, vínculo com imobiliária, perfil do solicitante e resultado.
                        </p>
                    </div>

                    <div class="text-xl-end">
                        <div class="fw-bold">
                            {{ $filteredLeads }} lead(s)
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
                            <div class="col-12 col-xl-3">
                                <label for="admin-lead-search" class="form-label small text-muted">
                                    Buscar lead
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

                            {{-- Vínculo com imobiliária --}}
                            <div class="col-12 col-md-6 col-xl-3">
                                <label for="admin-imobiliaria-filter" class="form-label small text-muted">
                                    Vínculo com imobiliária
                                </label>

                                <select id="admin-imobiliaria-filter" name="imobiliaria" class="form-select">
                                    <option value="">Todos os vínculos</option>

                                    <option
                                        value="sem_vinculo"
                                        @selected($selectedImobiliaria === 'sem_vinculo')
                                    >
                                        Sem imobiliária vinculada
                                    </option>

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

                            {{-- Perfil do solicitante --}}
                            <div class="col-12 col-md-6 col-xl-3">
                                <label for="admin-tipo-solicitante-filter" class="form-label small text-muted">
                                    Perfil do solicitante
                                </label>

                                <select
                                    id="admin-tipo-solicitante-filter"
                                    name="tipo_solicitante"
                                    class="form-select"
                                >
                                    <option value="">Todos os perfis</option>

                                    @foreach ($tipoSolicitantesOptions as $value => $label)
                                        <option
                                            value="{{ $value }}"
                                            @selected($selectedTipoSolicitante === $value)
                                        >
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Resultado --}}
                            <div class="col-12 col-md-6 col-xl-2">
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
                            <div class="col-12 col-md-6 col-xl-1 d-grid">
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
                                    Vínculo: {{ $selectedImobiliariaName ?? 'Selecionado' }}
                                    <i class="bi bi-x ms-1"></i>
                                </a>
                            @endif

                            @if (filled($selectedTipoSolicitante))
                                <a
                                    href="{{ request()->fullUrlWithQuery(['tipo_solicitante' => null, 'page' => 1]) }}#leads-section"
                                    class="badge rounded-pill text-bg-info text-decoration-none px-3 py-2"
                                >
                                    Perfil: {{ $tipoSolicitantesOptions[$selectedTipoSolicitante] ?? $selectedTipoSolicitante }}
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

                    {{-- Atalhos rápidos que preservam busca, vínculo e perfil --}}
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

        {{-- Lista de leads --}}
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
                            $tipoSolicitanteLabel = $getTipoSolicitanteLabel($lead);
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

                                                <span class="badge text-bg-info mt-1">
                                                    {{ $tipoSolicitanteLabel }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Vínculo/origem --}}
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <div class="small text-muted">
                                            Vínculo/origem
                                        </div>

                                        <div class="fw-semibold text-truncate">
                                            {{ $imobiliariaName }}
                                        </div>

                                        @if ($lead->tipo_solicitante === 'imobiliaria_nao_cadastrada')
                                            <div class="small text-muted">
                                                Nome informado no formulário; sem vínculo cadastrado.
                                            </div>
                                        @elseif ($lead->tipo_solicitante === 'locador' && filled($lead->locador?->nome))
                                            <div class="small text-muted text-truncate">
                                                Proprietário: {{ $lead->locador->nome }}
                                            </div>
                                        @endif
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
                            Exibindo {{ $currentStart }} a {{ $currentEnd }} de {{ $filteredLeads }} lead(s){{ $isFiltering ? ' filtrados' : '' }}.
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
                            Nenhum lead encontrado
                        </span>

                        @if ($isFiltering)
                            <h3 class="h5 fw-bold">
                                Nenhum lead corresponde aos filtros atuais.
                            </h3>

                            <p class="text-muted">
                                Tente alterar a busca, o vínculo, o perfil, o resultado ou limpar os filtros.
                            </p>

                            <a href="{{ $dashboardRoute }}#leads-section" class="btn btn-outline-secondary">
                                Limpar filtros
                            </a>
                        @else
                            <h3 class="h5 fw-bold">
                                Ainda não há leads cadastrados.
                            </h3>

                            <p class="text-muted">
                                Assim que uma imobiliária ou um solicitante público enviar uma simulação, o lead aparecerá aqui.
                            </p>
                        @endif
                    </div>
                </div>
            @endif
        @endcan
    </div>
</div>

@if ($canCreateAnalysis)
    {{-- Seleção global do formulário de nova análise --}}
    <div
        class="modal fade"
        id="adminSimulationModal"
        tabindex="-1"
        aria-labelledby="adminSimulationModalLabel"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-5 border-0 shadow-lg">
                <form
                    method="GET"
                    action="{{ $adminSimulationOpenRoute }}"
                    target="_blank"
                    id="adminSimulationSelectionForm"
                >
                    <div class="modal-header border-0 pb-0">
                        <div>
                            <h2 class="modal-title h5 fw-bold" id="adminSimulationModalLabel">
                                Escolha o formulário
                            </h2>
                            <p class="text-muted small mb-0 mt-2">
                                Defina se a solicitação será vinculada a uma imobiliária. O formulário será aberto em uma nova aba.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Fechar"
                        ></button>
                    </div>

                    <div class="modal-body p-4">
                        @if ($errors->adminSimulation->any())
                            <div class="alert alert-danger rounded-4">
                                <ul class="mb-0">
                                    @foreach ($errors->adminSimulation->all() as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="mb-3">
                            <label for="adminSimulationLinkType" class="form-label fw-semibold">
                                Vínculo
                            </label>
                            <select
                                name="vinculo"
                                id="adminSimulationLinkType"
                                class="form-select"
                                required
                            >
                                <option value="">Selecione</option>
                                <option
                                    value="imobiliaria_cadastrada"
                                    @selected(old('vinculo') === 'imobiliaria_cadastrada')
                                    @disabled($simulationCompanies->isEmpty())
                                >
                                    Vincular a uma imobiliária cadastrada
                                </option>
                                <option
                                    value="sem_vinculo"
                                    @selected(old('vinculo') === 'sem_vinculo')
                                >
                                    Não vincular a uma imobiliária
                                </option>
                            </select>
                        </div>

                        <div id="adminSimulationCompanyGroup" class="mb-3 d-none">
                            <label for="adminSimulationCompany" class="form-label fw-semibold">
                                Imobiliária
                            </label>
                            <select
                                name="company_id"
                                id="adminSimulationCompany"
                                class="form-select"
                                disabled
                            >
                                <option value="">Selecione</option>
                                @foreach ($simulationCompanies as $company)
                                    @php
                                        $companyCity = data_get($company, 'city')
                                            ?? data_get($company, 'cidade');
                                        $companyState = data_get($company, 'state')
                                            ?? data_get($company, 'uf');
                                        $companyLocation = collect([$companyCity, $companyState])
                                            ->filter(fn ($value) => filled($value))
                                            ->implode('/');
                                    @endphp
                                    <option
                                        value="{{ data_get($company, 'id') }}"
                                        @selected((string) old('company_id') === (string) data_get($company, 'id'))
                                    >
                                        {{ data_get($company, 'name', data_get($company, 'nome', 'Imobiliária')) }}{{ $companyLocation ? ' — '.$companyLocation : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                O tipo será registrado automaticamente como imobiliária cadastrada.
                            </div>
                        </div>

                        <div id="adminSimulationTypeGroup" class="mb-3 d-none">
                            <label for="adminSimulationType" class="form-label fw-semibold">
                                Tipo do solicitante
                            </label>
                            <select
                                name="tipo_solicitante"
                                id="adminSimulationType"
                                class="form-select"
                                disabled
                            >
                                <option value="">Selecione</option>
                                <option
                                    value="imobiliaria_nao_cadastrada"
                                    @selected(old('tipo_solicitante') === 'imobiliaria_nao_cadastrada')
                                >
                                    Imobiliária não cadastrada
                                </option>
                                <option value="locatario" @selected(old('tipo_solicitante') === 'locatario')>
                                    Locatário
                                </option>
                                <option value="locador" @selected(old('tipo_solicitante') === 'locador')>
                                    Proprietário / locador
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" @disabled(! $adminSimulationRouteExists)>
                            Abrir formulário
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const linkType = document.getElementById('adminSimulationLinkType');
            const companyGroup = document.getElementById('adminSimulationCompanyGroup');
            const company = document.getElementById('adminSimulationCompany');
            const typeGroup = document.getElementById('adminSimulationTypeGroup');
            const type = document.getElementById('adminSimulationType');

            if (!linkType || !companyGroup || !company || !typeGroup || !type) {
                return;
            }

            const updateSimulationFields = function () {
                const usesRegisteredCompany = linkType.value === 'imobiliaria_cadastrada';
                const hasNoCompanyLink = linkType.value === 'sem_vinculo';

                companyGroup.classList.toggle('d-none', !usesRegisteredCompany);
                company.disabled = !usesRegisteredCompany;
                company.required = usesRegisteredCompany;

                typeGroup.classList.toggle('d-none', !hasNoCompanyLink);
                type.disabled = !hasNoCompanyLink;
                type.required = hasNoCompanyLink;
            };

            updateSimulationFields();
            linkType.addEventListener('change', updateSimulationFields);

            @if ($errors->adminSimulation->any())
                const modalElement = document.getElementById('adminSimulationModal');

                if (modalElement && window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
                }
            @endif
        });
    </script>
@endif

{{-- Modais dos leads --}}
@can('view-leads')
    @foreach ($leads as $lead)
        @php
            $leadName = $lead->nome ?: 'Cliente sem nome';

            $imobiliariaName = $getImobiliariaName($lead);
            $tipoSolicitanteLabel = $getTipoSolicitanteLabel($lead);

            $allTags = collect(preg_split('/\s*,\s*/', $lead->tags_originais ?? ''))
                ->filter(fn ($tag) => filled($tag))
                ->map(fn ($tag) => trim($tag));

            $resultTone = $getLeadResultTone($allTags);

            $lastAnalysis = $lead->insuranceAnalyses
                ->sortByDesc('created_at')
                ->first();

            $canReanalyze = filled($lead->reanalysis_unlocked_at)
                && (! $lastAnalysis
                    || $lead->reanalysis_unlocked_at->gt($lastAnalysis->created_at));

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
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <span class="badge {{ $resultTone['badge'] }}">
                                    {{ $resultTone['label'] }}
                                </span>

                                <span class="badge text-bg-info">
                                    {{ $tipoSolicitanteLabel }}
                                </span>
                            </div>

                            <h5 class="modal-title fw-bold" id="adminLeadModalLabel{{ $lead->id }}">
                                {{ $leadName }}
                            </h5>

                            <p class="text-muted small mb-0">
                                Vínculo/origem: {{ $imobiliariaName }}
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
                                    Dados para reanálise
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

                            @can('create-analysis')
                                <li class="nav-item" role="presentation">
                                    <button
                                        class="nav-link"
                                        data-bs-toggle="pill"
                                        data-bs-target="#admin-lead-reanalysis-pane-{{ $lead->id }}"
                                        type="button"
                                        role="tab"
                                    >
                                        Reanálise
                                    </button>
                                </li>
                            @endcan
                        </ul>

                        <div class="tab-content">


                            <div
                                class="tab-pane fade show active"
                                id="admin-lead-data-pane-{{ $lead->id }}"
                                role="tabpanel"
                            >
                            {{-- Dados do cliente --}}
                                @can('edit-leads')
                                    <form
                                        method="POST"
                                        action="{{ $adminUpdateLeadRoute($lead) }}"
                                        id="adminLeadUpdateForm{{ $lead->id }}"
                                        class="lead-update-form"
                                    >
                                        @csrf
                                        @method('PUT')
                                @else
                                    <div class="alert alert-info rounded-4 d-flex align-items-start gap-2" role="status">
                                        <i class="bi bi-eye mt-1" aria-hidden="true"></i>
                                        <div>
                                            <strong>Visualização somente leitura.</strong>
                                            Você pode consultar todos os dados deste lead, mas não possui permissão para editá-los.
                                        </div>
                                    </div>

                                    <fieldset disabled aria-label="Dados do lead disponíveis somente para visualização">
                                @endcan

                                        <div class="row g-4">
                                            <div class="col-12">
                                                <div class="card border rounded-4">
                                                    <div class="card-body">
                                                        <h6 class="fw-bold mb-3">
                                                            Dados do solicitante
                                                        </h6>

                                                        <div class="row g-3">
                                                            <div class="col-12 col-md-6">
                                                                <label class="form-label">Nome</label>
                                                                <input
                                                                    type="text"
                                                                    name="nome"
                                                                    class="form-control"
                                                                    value="{{ old('nome', $lead->nome) }}"
                                                                >
                                                            </div>

                                                            <div class="col-12 col-md-6">
                                                                <label class="form-label">E-mail</label>
                                                                <input
                                                                    type="email"
                                                                    name="email"
                                                                    class="form-control"
                                                                    value="{{ old('email', $lead->email) }}"
                                                                >
                                                            </div>

                                                            <div class="col-12 col-md-4">
                                                                <label class="form-label">Telefone</label>
                                                                <input
                                                                    type="text"
                                                                    name="tel"
                                                                    class="form-control"
                                                                    value="{{ old('tel', $lead->tel) }}"
                                                                >
                                                            </div>

                                                            <div class="col-12 col-md-4">
                                                                <label class="form-label">CPF</label>
                                                                <input
                                                                    type="text"
                                                                    name="cpf"
                                                                    class="form-control"
                                                                    value="{{ old('cpf', $lead->cpf) }}"
                                                                >
                                                            </div>

                                                            <div class="col-12 col-md-4">
                                                                <label class="form-label">Estado civil</label>
                                                                <input
                                                                    type="text"
                                                                    name="estado_civil"
                                                                    class="form-control"
                                                                    value="{{ old('estado_civil', $lead->estado_civil) }}"
                                                                >
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            {{-- Endereço --}}
                                            <div class="col-12">
                                                <div class="card border rounded-4">
                                                    <div class="card-body">
                                                        <h6 class="fw-bold mb-3">
                                                            Endereço do imóvel
                                                        </h6>

                                                        <div class="row g-3">
                                                            <div class="col-12 col-md-3">
                                                                <label class="form-label">CEP</label>
                                                                <input
                                                                    type="text"
                                                                    name="cep"
                                                                    class="form-control"
                                                                    value="{{ old('cep', $lead->endereco?->cep) }}"
                                                                >
                                                            </div>

                                                            <div class="col-12 col-md-2">
                                                                <label class="form-label">UF</label>
                                                                <input
                                                                    type="text"
                                                                    name="estado"
                                                                    class="form-control"
                                                                    value="{{ old('estado', $lead->endereco?->estado) }}"
                                                                >
                                                            </div>

                                                            <div class="col-12 col-md-4">
                                                                <label class="form-label">Cidade</label>
                                                                <input
                                                                    type="text"
                                                                    name="cidade_imovel"
                                                                    class="form-control"
                                                                    value="{{ old('cidade_imovel', $lead->endereco?->cidade_imovel) }}"
                                                                >
                                                            </div>

                                                            <div class="col-12 col-md-3">
                                                                <label class="form-label">Bairro</label>
                                                                <input
                                                                    type="text"
                                                                    name="bairro"
                                                                    class="form-control"
                                                                    value="{{ old('bairro', $lead->endereco?->bairro) }}"
                                                                >
                                                            </div>

                                                            <div class="col-12 col-md-8">
                                                                <label class="form-label">Logradouro</label>
                                                                <input
                                                                    type="text"
                                                                    name="logradouro"
                                                                    class="form-control"
                                                                    value="{{ old('logradouro', $lead->endereco?->logradouro) }}"
                                                                >
                                                            </div>

                                                            <div class="col-12 col-md-2">
                                                                <label class="form-label">Número</label>
                                                                <input
                                                                    type="text"
                                                                    name="numero"
                                                                    class="form-control"
                                                                    value="{{ old('numero', $lead->endereco?->numero) }}"
                                                                >
                                                            </div>

                                                            <div class="col-12 col-md-2">
                                                                <label class="form-label">Complemento</label>
                                                                <input
                                                                    type="text"
                                                                    name="complemento"
                                                                    class="form-control"
                                                                    value="{{ old('complemento', $lead->endereco?->complemento) }}"
                                                                >
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            {{-- Despesas --}}
                                            <div class="col-12">
                                                <div class="card border rounded-4">
                                                    <div class="card-body">
                                                        <h6 class="fw-bold mb-3">
                                                            Valores da locação
                                                        </h6>

                                                        <div class="row g-3">
                                                            <div class="col-6 col-md-3">
                                                                <label class="form-label">Aluguel</label>
                                                                <input
                                                                    type="number"
                                                                    step="0.01"
                                                                    min="0"
                                                                    name="valor_aluguel"
                                                                    class="form-control"
                                                                    value="{{ old('valor_aluguel', $lead->despesas?->valor_aluguel) }}"
                                                                >
                                                            </div>

                                                            <div class="col-6 col-md-3">
                                                                <label class="form-label">Água</label>
                                                                <input
                                                                    type="number"
                                                                    step="0.01"
                                                                    min="0"
                                                                    name="valor_agua"
                                                                    class="form-control"
                                                                    value="{{ old('valor_agua', $lead->despesas?->valor_agua) }}"
                                                                >
                                                            </div>

                                                            <div class="col-6 col-md-3">
                                                                <label class="form-label">Luz</label>
                                                                <input
                                                                    type="number"
                                                                    step="0.01"
                                                                    min="0"
                                                                    name="valor_luz"
                                                                    class="form-control"
                                                                    value="{{ old('valor_luz', $lead->despesas?->valor_luz) }}"
                                                                >
                                                            </div>

                                                            <div class="col-6 col-md-3">
                                                                <label class="form-label">Gás</label>
                                                                <input
                                                                    type="number"
                                                                    step="0.01"
                                                                    min="0"
                                                                    name="valor_gas"
                                                                    class="form-control"
                                                                    value="{{ old('valor_gas', $lead->despesas?->valor_gas) }}"
                                                                >
                                                            </div>

                                                            <div class="col-6 col-md-4">
                                                                <label class="form-label">Condomínio</label>
                                                                <input
                                                                    type="number"
                                                                    step="0.01"
                                                                    min="0"
                                                                    name="valor_condominio"
                                                                    class="form-control"
                                                                    value="{{ old('valor_condominio', $lead->despesas?->valor_condominio) }}"
                                                                >
                                                            </div>

                                                            <div class="col-6 col-md-4">
                                                                <label class="form-label">IPTU</label>
                                                                <input
                                                                    type="number"
                                                                    step="0.01"
                                                                    min="0"
                                                                    name="valor_iptu"
                                                                    class="form-control"
                                                                    value="{{ old('valor_iptu', $lead->despesas?->valor_iptu) }}"
                                                                >
                                                            </div>

                                                            <div class="col-12 col-md-4">
                                                                <label class="form-label">Outras despesas</label>
                                                                <input
                                                                    type="number"
                                                                    step="0.01"
                                                                    min="0"
                                                                    name="outras_despesas"
                                                                    class="form-control"
                                                                    value="{{ old('outras_despesas', $lead->despesas?->outras_despesas) }}"
                                                                >
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                @can('edit-leads')
                                    </form>
                                @else
                                    </fieldset>
                                @endcan
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


                            @can('create-analysis')
                                <div
                                    class="tab-pane fade"
                                    id="admin-lead-reanalysis-pane-{{ $lead->id }}"
                                    role="tabpanel"
                                >
                                    @if ($canReanalyze)
                                        <div class="alert alert-success rounded-4">
                                            <strong>Reanálise liberada.</strong>
                                            Este cliente possui alterações salvas depois da última análise.
                                        </div>

                                        <form
                                            method="POST"
                                            action="{{ $solicitarAnaliseRoute($lead) }}"
                                        >
                                            @csrf

                                            <button
                                                type="submit"
                                                class="btn btn-warning"
                                                @disabled($solicitarAnaliseRoute($lead) === '#')
                                            >
                                                <i class="bi bi-arrow-repeat me-1"></i>
                                                Solicitar reanálise
                                            </button>
                                        </form>
                                    @else
                                        <div class="alert alert-info rounded-4">
                                            <strong>Reanálise bloqueada.</strong>
                                            Para solicitar uma nova análise, altere algum dado do cliente e clique em
                                            <strong>Salvar alterações</strong>.
                                        </div>

                                        @if ($lastAnalysis)
                                            <p class="text-muted small mb-0">
                                                Última análise registrada em:
                                                {{ $lastAnalysis->created_at->format('d/m/Y H:i') }}
                                            </p>
                                        @else
                                            <p class="text-muted small mb-0">
                                                Nenhuma análise anterior foi encontrada para este cliente.
                                            </p>
                                        @endif
                                    @endif
                                </div>
                            @endcan
                        </div>
                    </div>

                    <div class="modal-footer border-0 pt-0">
                        @can('edit-leads')
                            <button
                                type="submit"
                                form="adminLeadUpdateForm{{ $lead->id }}"
                                class="btn btn-primary"
                            >
                                Salvar alterações
                            </button>
                        @endcan

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
