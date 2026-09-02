@php
    use Illuminate\Support\Facades\Route;

    /*
    |--------------------------------------------------------------------------
    | Dados do corretor/admin logado
    |--------------------------------------------------------------------------
    */

    $adminUser = auth('admin')->user();

    $adminName = $adminUser?->nome
        ?? $adminUser?->name
        ?? 'Corretor';

    $adminEmail = $adminUser?->email
        ?? 'E-mail não informado';

    $adminRole = $adminUser?->role
        ?? 'integrante';

    $isCeo = method_exists($adminUser, 'isCeo')
        ? $adminUser->isCeo()
        : $adminRole === 'CEO';

    $adminInitials = collect(preg_split('/\s+/', trim($adminName)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');

    /*
    |--------------------------------------------------------------------------
    | Notificações
    |--------------------------------------------------------------------------
    | Por enquanto usa os leads novos enviados pelo CorretorDashboardController.
    */

    $notificationCount = $dashboardStats['newLeads'] ?? 0;

    $insuranceAnalysisEnabled = (bool) config(
        'features.insurance_analysis.enabled',
        false
    );

    /*
    |--------------------------------------------------------------------------
    | Rotas principais do dashboard admin/corretores
    |--------------------------------------------------------------------------
    */

    $dashboardRoute = Route::has('Dashboard-Admin')
        ? route('Dashboard-Admin')
        : '#';

    $leadsRoute = Route::has('admin.leads.index')
        ? route('admin.leads.index')
        : ($dashboardRoute !== '#' ? $dashboardRoute . '#leads-section' : '#');

    $analisesRoute = Route::has('admin.insurance-analyses.index')
        ? route('admin.insurance-analyses.index')
        : (Route::has('insurance-analyses.index') ? route('insurance-analyses.index') : '#');

    $equipeRoute = Route::has('admin.config-equipe.index')
        ? route('admin.config-equipe.index')
        : '#';

    $imobiliariasRoute = Route::has('admin.imobiliarias.index')
        ? route('admin.imobiliarias.index')
        : '#';

    $logoutRoute = Route::has('admin.logout')
        ? route('admin.logout')
        : '#';

    $brandProfile = config('branding.active', 'tcc');
    $brandName = config(
        "branding.profiles.{$brandProfile}.name",
        'NVS Seguros'
    );
    $panelLabel = $isCeo ? 'Painel do CEO' : 'Painel do corretor';
    $operationLabel = $brandProfile === 'client'
        ? 'Operação ativa'
        : 'Sistema operacional';
    $currentSectionLabel = match (true) {
        request()->routeIs('admin.imobiliarias.*') => 'Imobiliárias',
        request()->routeIs('admin.insurance-analyses.*') => 'Propostas',
        request()->routeIs('admin.config-equipe.*') => 'Equipe',
        default => 'Central de leads',
    };
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
                data-bs-target="#dashboardAdminSidebar"
                aria-controls="dashboardAdminSidebar"
                aria-label="Abrir menu lateral"
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

                @can('view-leads')
                    <a
                        class="dashboard-header-nav__link {{ request()->routeIs('Dashboard-Admin') || request()->routeIs('admin.leads.*') ? 'active' : '' }}"
                        href="{{ $leadsRoute }}"
                        @if (request()->routeIs('Dashboard-Admin') || request()->routeIs('admin.leads.*')) aria-current="page" @endif
                    >
                        Leads
                    </a>
                @endcan

                @can('view-real-estate-companies')
                    <a
                        class="dashboard-header-nav__link {{ request()->routeIs('admin.imobiliarias.*') ? 'active' : '' }}"
                        href="{{ $imobiliariasRoute }}"
                        @if (request()->routeIs('admin.imobiliarias.*')) aria-current="page" @endif
                    >
                        Imobiliárias
                    </a>
                @endcan

                @if ($insuranceAnalysisEnabled)
                    @can('view-analyses')
                        <a
                            class="dashboard-header-nav__link {{ request()->routeIs('admin.insurance-analyses.*') ? 'active' : '' }}"
                            href="{{ $analisesRoute }}"
                            @if (request()->routeIs('admin.insurance-analyses.*')) aria-current="page" @endif
                        >
                            Propostas
                        </a>
                    @endcan
                @endif

                <span class="dashboard-header-nav__link dashboard-header-nav__link--disabled" aria-disabled="true">
                    Relatórios
                </span>
            </nav>

            <div class="dashboard-header-actions">
                @if ($brandProfile === 'tcc')
                    @include('layout-inicial.partials.dashboard-header-notifications', [
                        'notificationCount' => $notificationCount,
                        'leadsRoute' => $leadsRoute,
                        'notificationDescription' => 'Acompanhe novos clientes recebidos pelas imobiliárias.',
                        'notificationItemLabel' => 'novo(s) cliente(s)',
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
                        'notificationDescription' => 'Acompanhe novos clientes recebidos pelas imobiliárias.',
                        'notificationItemLabel' => 'novo(s) cliente(s)',
                    ])
                    <span class="dashboard-header-separator" aria-hidden="true"></span>
                @endif

                <div class="dropdown dashboard-profile">
                    <button
                        class="btn dashboard-profile-btn d-flex align-items-center gap-2"
                        type="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        aria-label="Abrir menu de {{ $adminName }}"
                    >
                        <span class="dashboard-profile-avatar">
                            {{ $adminInitials ?: 'CO' }}
                        </span>

                        <span class="dashboard-profile-copy d-none d-lg-flex">
                            <strong>{{ $adminName }}</strong>
                            <small>{{ $adminRole }}</small>
                        </span>

                        <i class="bi bi-chevron-down dashboard-profile-chevron" aria-hidden="true"></i>
                    </button>

                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-4 dashboard-profile-menu">
                        <li class="px-3 py-2 border-bottom">
                            <div class="fw-bold">{{ $adminName }}</div>
                            <div class="small text-muted">{{ $adminEmail }}</div>
                            <span class="badge {{ $isCeo ? 'text-bg-primary' : 'text-bg-secondary' }} mt-2">
                                {{ $adminRole }}
                            </span>
                        </li>

                        @can('manage-organization')
                            <li>
                                <a class="dropdown-item py-2" href="{{ $equipeRoute }}">
                                    <i class="bi bi-person-gear me-2" aria-hidden="true"></i>
                                    Gerenciar equipe
                                </a>
                            </li>
                        @endcan

                        @if ($insuranceAnalysisEnabled)
                            @can('view-analyses')
                                <li>
                                    <a class="dropdown-item py-2" href="{{ $analisesRoute }}">
                                        <i class="bi bi-clipboard2-data me-2" aria-hidden="true"></i>
                                        Visualizar análises
                                    </a>
                                </li>
                            @endcan
                        @endif

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
                            <form method="POST" action="{{ $logoutRoute }}">
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
    id="dashboardAdminSidebar"
    aria-labelledby="dashboardAdminSidebarLabel"
>
    <div class="offcanvas-header border-bottom">
        <div class="d-flex align-items-center gap-3">
            <span class="dashboard-sidebar-logo">
                <x-brand-logo variant="logo_header" />
            </span>

            <div>
                <h5 class="offcanvas-title fw-bold mb-0" id="dashboardAdminSidebarLabel">
                    {{ config('branding.profiles.'.config('branding.active', 'tcc').'.name', 'NVS Seguros') }}
                </h5>

                <small class="text-muted">
                    {{ $isCeo ? 'Menu do CEO' : 'Menu do corretor' }}
                </small>
            </div>
        </div>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="offcanvas"
            aria-label="Fechar"
        ></button>
    </div>

    <div class="offcanvas-body p-3">

        {{-- Perfil resumido --}}
        <div class="dashboard-sidebar-profile rounded-4 p-3 mb-3">
            <div class="d-flex align-items-center gap-3">
                <span class="dashboard-profile-avatar">
                    {{ $adminInitials ?: 'CO' }}
                </span>

                <div class="min-w-0">
                    <div class="fw-bold text-truncate">
                        {{ $adminName }}
                    </div>

                    <div class="small text-muted text-truncate">
                        {{ $adminEmail }}
                    </div>

                    <span class="badge {{ $isCeo ? 'text-bg-primary' : 'text-bg-secondary' }} mt-2">
                        {{ $adminRole }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Navegação principal --}}
        <nav class="dashboard-sidebar-nav">

            {{-- Dashboard / Clientes --}}
            @can('view-leads')
                <a
                    href="{{ $dashboardRoute }}"
                    class="dashboard-sidebar-link {{ request()->routeIs('Dashboard-Admin') ? 'active' : '' }}"
                >
                    <i class="bi bi-grid-1x2" aria-hidden="true"></i>
                    <span>Dashboard</span>
                </a>
            @endcan

            {{-- Visualizar análises --}}
            @if ($insuranceAnalysisEnabled)
                @can('view-analyses')
                    <a
                        href="{{ $analisesRoute }}"
                        class="dashboard-sidebar-link {{ request()->routeIs('admin.insurance-analyses.*') || request()->routeIs('insurance-analyses.*') ? 'active' : '' }}"
                    >
                        <i class="bi bi-clipboard2-data" aria-hidden="true"></i>
                        <span>Visualizar análises</span>
                    </a>
                @endcan
            @endif

            {{-- Equipe: somente CEO --}}
            @can('manage-organization')
                <a
                    href="{{ $equipeRoute }}"
                    class="dashboard-sidebar-link {{ request()->routeIs('admin.config-equipe.*') ? 'active' : '' }}"
                >
                    <i class="bi bi-person-gear" aria-hidden="true"></i>
                    <span>Equipe</span>
                </a>
            @endcan

            {{-- Imobiliárias --}}
            @can('view-real-estate-companies')
                <a
                    href="{{ $imobiliariasRoute }}"
                    class="dashboard-sidebar-link {{ request()->routeIs('admin.imobiliarias.*') ? 'active' : '' }} {{ $imobiliariasRoute === '#' ? 'disabled opacity-50' : '' }}"
                    @if ($imobiliariasRoute === '#') aria-disabled="true" tabindex="-1" @endif
                >
                    <i class="bi bi-buildings" aria-hidden="true"></i>
                    <span>Imobiliárias</span>
                </a>
            @endcan

            {{-- Leads --}}
            @can('view-leads')
                <a
                    href="{{ $leadsRoute }}"
                    class="dashboard-sidebar-link {{ request()->routeIs('admin.leads.*') ? 'active' : '' }}"
                >
                    <i class="bi bi-people" aria-hidden="true"></i>
                    <span>Leads</span>
                </a>
            @endcan

            {{-- Ajuda --}}
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

        {{-- Rodapé --}}
        <div class="dashboard-sidebar-footer mt-4 pt-3 border-top">
            <form method="POST" action="{{ $logoutRoute }}">
                @csrf

                <button
                    type="submit"
                    class="dashboard-sidebar-link dashboard-sidebar-link-danger border-0 bg-transparent w-100 text-start"
                >
                    <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                    <span>Sair</span>
                </button>
            </form>
        </div>
    </div>
</div>
