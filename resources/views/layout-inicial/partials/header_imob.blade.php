@php
    /*
    |--------------------------------------------------------------------------
    | Dados usados no header do dashboard
    |--------------------------------------------------------------------------
    | Este bloco tenta usar a variável $company enviada pelo DashboardController.
    | Se ela não existir em alguma página, tenta pegar a company do usuário logado.
    */

    $dashboardUser = auth()->user();

    $dashboardCompany = $company
        ?? $dashboardUser?->company
        ?? null;

    $companyName = $dashboardCompany?->name
        ?? $dashboardUser?->name
        ?? 'Imobiliária';

    $companyEmail = $dashboardCompany?->email
        ?? $dashboardUser?->email
        ?? 'E-mail não informado';

    /*
    |--------------------------------------------------------------------------
    | Iniciais para avatar quando não houver imagem de perfil
    |--------------------------------------------------------------------------
    */
    $companyInitials = collect(preg_split('/\s+/', trim($companyName)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');

    /*
    |--------------------------------------------------------------------------
    | Notificações simples
    |--------------------------------------------------------------------------
    | Por enquanto usamos a quantidade de leads novos já calculada no dashboard.
    | Depois isso pode evoluir para uma tabela real de notificações.
    */
    $notificationCount = $dashboardStats['newLeads'] ?? 0;

    $insuranceAnalysisEnabled = (bool) config(
        'features.insurance_analysis.enabled',
        false
    );

    $brandProfile = config('branding.active', 'tcc');
    $brandName = config(
        "branding.profiles.{$brandProfile}.name",
        'NVS Seguros'
    );
    $dashboardRoute = route('company.dashboard');
    $leadsRoute = $dashboardRoute.'#leads-section';
    $simulationRoute = route('simulation.registered-company.access');
    $panelLabel = 'Painel da imobiliária';
    $operationLabel = $brandProfile === 'client'
        ? 'Operação ativa'
        : 'Sistema operacional';
    $currentSectionLabel = request()->routeIs('insurance-analyses.*')
        ? 'Análises'
        : 'Central de leads';
@endphp

<header
    class="dashboard-client-header sticky-top"
    data-dashboard-header="{{ $brandProfile }}"
    x-data="{ isCompact: window.scrollY > 24 }"
    x-on:scroll.window.throttle.100ms="isCompact = window.scrollY > 24"
    x-bind:class="{ 'is-compact': isCompact }"
>
    <div class="dashboard-client-header__primary">
        <div class="dashboard-client-header__rail">
            <button
                class="btn dashboard-sidebar-toggle"
                type="button"
                data-bs-toggle="offcanvas"
                data-bs-target="#dashboardClientSidebar"
                aria-controls="dashboardClientSidebar"
                aria-label="Abrir navegação"
            >
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>
        </div>

        <div class="navbar navbar-expand dashboard-client-navbar">
            <a class="navbar-brand dashboard-client-brand" href="{{ $dashboardRoute }}">
                <span class="dashboard-client-brand__logo">
                    <x-brand-logo variant="logo_header" />
                </span>

                <span class="dashboard-client-brand__text d-none d-sm-flex">
                    <strong>{{ $brandName }}</strong>
                    <small>{{ $panelLabel }}</small>
                </span>
            </a>

            <nav class="dashboard-header-nav" aria-label="Seções do painel">
                <a class="dashboard-header-nav__link" href="{{ $dashboardRoute }}">
                    Visão geral
                </a>
                <a
                    class="dashboard-header-nav__link {{ request()->routeIs('company.dashboard') ? 'active' : '' }}"
                    href="{{ $leadsRoute }}"
                    @if (request()->routeIs('company.dashboard')) aria-current="page" @endif
                >
                    Leads
                </a>

                @if ($insuranceAnalysisEnabled)
                    <a
                        class="dashboard-header-nav__link {{ request()->routeIs('insurance-analyses.*') ? 'active' : '' }}"
                        href="{{ route('insurance-analyses.index') }}"
                        @if (request()->routeIs('insurance-analyses.*')) aria-current="page" @endif
                    >
                        Análises
                    </a>
                @endif

                <a class="dashboard-header-nav__link" href="{{ $simulationRoute }}">
                    Simulação
                </a>
            </nav>

            <div class="dashboard-header-actions">
                @if ($brandProfile === 'tcc')
                    @include('layout-inicial.partials.dashboard-header-notifications', [
                        'notificationCount' => $notificationCount,
                        'leadsRoute' => $leadsRoute,
                        'notificationDescription' => 'Acompanhe os novos leads recebidos.',
                        'notificationItemLabel' => 'lead(s) novo(s)',
                    ])
                    <span class="dashboard-header-separator" aria-hidden="true"></span>
                @endif

                <span class="dashboard-header-status">
                    <span class="dashboard-header-status__dot" aria-hidden="true"></span>
                    {{ $operationLabel }}
                </span>

                <span class="dashboard-header-separator" aria-hidden="true"></span>

                @if ($brandProfile !== 'tcc')
                    @include('layout-inicial.partials.dashboard-header-notifications', [
                        'notificationCount' => $notificationCount,
                        'leadsRoute' => $leadsRoute,
                        'notificationDescription' => 'Acompanhe os novos leads recebidos.',
                        'notificationItemLabel' => 'lead(s) novo(s)',
                    ])
                    <span class="dashboard-header-separator" aria-hidden="true"></span>
                @endif

                <div class="dropdown dashboard-profile">
                    <button
                        class="btn dashboard-profile-btn d-flex align-items-center gap-2"
                        type="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        aria-label="Abrir menu de {{ $companyName }}"
                    >
                        <span class="dashboard-profile-avatar">
                            {{ $companyInitials ?: 'IM' }}
                        </span>

                        <span class="dashboard-profile-copy d-none d-lg-flex">
                            <strong>{{ $companyName }}</strong>
                            <small>{{ $companyEmail }}</small>
                        </span>

                        <i class="bi bi-chevron-down dashboard-profile-chevron" aria-hidden="true"></i>
                    </button>

                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-4 dashboard-profile-menu">
                        <li class="px-3 py-2 border-bottom">
                            <div class="fw-bold">{{ $companyName }}</div>
                            <div class="small text-muted">{{ $companyEmail }}</div>
                        </li>

                        <li>
                            <a class="dropdown-item py-2" href="{{ route('profile.edit') }}">
                                <i class="bi bi-gear me-2" aria-hidden="true"></i>
                                Gerenciar conta
                            </a>
                        </li>

                        <li>
                            <a
                                class="dropdown-item py-2"
                                href="https://api.whatsapp.com/send?phone=5511999999999&text=Ola,%20gostaria%20de%20tirar%20uma%20duvida"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <i class="bi bi-question-circle me-2" aria-hidden="true"></i>
                                Tirar dúvidas
                            </a>
                        </li>

                        <li><hr class="dropdown-divider"></li>

                        <li>
                            <form method="POST" action="{{ route('empresa.logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item py-2 text-danger">
                                    <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>
                                    Sair
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-client-header__secondary">
        <div class="dashboard-header-breadcrumb" aria-label="Localização atual">
            <span>Dashboard</span>
            <i class="bi bi-slash-lg" aria-hidden="true"></i>
            <strong>{{ $currentSectionLabel }}</strong>
        </div>
    </div>
</header>

{{-- Menu lateral vertical --}}
<div
    class="offcanvas offcanvas-start dashboard-client-sidebar"
    tabindex="-1"
    id="dashboardClientSidebar"
    aria-labelledby="dashboardClientSidebarLabel"
>
    <div class="offcanvas-header border-bottom">
        <div class="d-flex align-items-center gap-3">
            <span class="dashboard-sidebar-logo">
                <x-brand-logo variant="logo_header" />
            </span>

            <div>
                <h5 class="offcanvas-title fw-bold mb-0" id="dashboardClientSidebarLabel">
                    {{ config('branding.profiles.'.config('branding.active', 'tcc').'.name', 'NVS Seguros') }}
                </h5>
                <small class="text-muted">Menu da imobiliária</small>
            </div>
        </div>

        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
    </div>

    <div class="offcanvas-body p-3">

        {{-- Perfil resumido dentro do menu lateral --}}
        <div class="dashboard-sidebar-profile rounded-4 p-3 mb-3">
            <div class="d-flex align-items-center gap-3">
                <span class="dashboard-profile-avatar">
                    {{ $companyInitials ?: 'IM' }}
                </span>

                <div class="min-w-0">
                    <div class="fw-bold text-truncate">{{ $companyName }}</div>
                    <div class="small text-muted text-truncate">{{ $companyEmail }}</div>
                </div>
            </div>
        </div>

        {{-- Navegação principal --}}
        <nav class="dashboard-sidebar-nav">
            <a href="{{ route('company.dashboard') }}" class="dashboard-sidebar-link active">
                <i class="bi bi-grid-1x2" aria-hidden="true"></i>
                <span>Dashboard</span>
            </a>

            <a href="{{ route('company.dashboard') }}#leads-section" class="dashboard-sidebar-link">
                <i class="bi bi-people" aria-hidden="true"></i>
                <span>Leads</span>
            </a>

            @if ($insuranceAnalysisEnabled)
                <a href="{{ route('insurance-analyses.index') }}" class="dashboard-sidebar-link">
                    <i class="bi bi-clipboard2-data" aria-hidden="true"></i>
                    <span>Análises</span>
                </a>
            @endif

            <a href="{{ route('simulation.registered-company.access') }}" class="dashboard-sidebar-link">
                <i class="bi bi-link-45deg" aria-hidden="true"></i>
                <span>Página de simulação</span>
            </a>

            <a href="{{ route('profile.edit') }}" class="dashboard-sidebar-link">
                <i class="bi bi-person-gear" aria-hidden="true"></i>
                <span>Gerenciar conta</span>
            </a>

            <a
                href="https://api.whatsapp.com/send?phone=5511999999999&text=Ola,%20gostaria%20de%20tirar%20uma%20duvida"
                target="_blank"
                rel="noopener noreferrer"
                class="dashboard-sidebar-link"
            >
                <i class="bi bi-question-circle" aria-hidden="true"></i>
                <span>Tirar dúvidas</span>
            </a>
        </nav>

        {{-- Área inferior do menu --}}
        <div class="dashboard-sidebar-footer mt-4 pt-3 border-top">
            <form method="POST" action="{{ route('empresa.logout') }}">
                @csrf
                <button type="submit" class="dashboard-sidebar-link dashboard-sidebar-link-danger border-0 w-100">
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    <span>Sair</span>
                </button>
            </form>
        </div>
    </div>
</div>
