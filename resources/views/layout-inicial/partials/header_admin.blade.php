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
@endphp

<header class="dashboard-client-header sticky-top">
    <nav class="navbar navbar-expand dashboard-client-navbar">
        <div class="container-fluid px-3 px-lg-4">

            {{-- Botão que abre o menu lateral --}}
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

            {{-- Logo / marca --}}
            <a class="navbar-brand dashboard-client-brand ms-2" href="{{ $dashboardRoute }}">
                <span class="dashboard-client-brand__logo">
                    <x-brand-logo variant="logo_header" />
                </span>

                <span class="dashboard-client-brand__text d-none d-md-flex">
                    <strong>{{ config('branding.profiles.'.config('branding.active', 'tcc').'.name', 'NVS Seguros') }}</strong>
                    <small>{{ $isCeo ? 'Painel do CEO' : 'Painel do corretor' }}</small>
                </span>
            </a>

            {{-- Área direita do header --}}
            <div class="ms-auto d-flex align-items-center gap-2 gap-md-3">

                {{-- Sino de notificações --}}
                <div class="dropdown">
                    <button
                        class="btn dashboard-notification-btn position-relative"
                        type="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        aria-label="Abrir notificações"
                    >
                        <i class="bi bi-bell" aria-hidden="true"></i>

                        @if ($notificationCount > 0)
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                {{ $notificationCount > 99 ? '99+' : $notificationCount }}
                            </span>
                        @endif
                    </button>

                    <div class="dropdown-menu dropdown-menu-end dashboard-notification-menu shadow border-0 rounded-4 p-0">
                        <div class="p-3 border-bottom">
                            <h6 class="fw-bold mb-1">
                                Notificações
                            </h6>

                            <p class="text-muted small mb-0">
                                Acompanhe novos clientes recebidos pelas imobiliárias.
                            </p>
                        </div>

                        <div class="p-3">
                            @if ($notificationCount > 0)
                                <div class="d-flex gap-3 align-items-start">
                                    <span class="dashboard-notification-icon bg-primary-subtle text-primary">
                                        <i class="bi bi-person-plus" aria-hidden="true"></i>
                                    </span>

                                    <div>
                                        <div class="fw-semibold">
                                            {{ $notificationCount }} novo(s) cliente(s)
                                        </div>

                                        <div class="small text-muted">
                                            Existem leads recentes aguardando acompanhamento.
                                        </div>

                                        <a href="{{ $leadsRoute }}" class="small fw-semibold text-decoration-none">
                                            Ver leads
                                        </a>
                                    </div>
                                </div>
                            @else
                                <div class="text-center py-3">
                                    <i class="bi bi-check-circle text-success fs-4" aria-hidden="true"></i>

                                    <p class="small text-muted mb-0 mt-2">
                                        Nenhuma nova notificação no momento.
                                    </p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Perfil do corretor/admin --}}
                <div class="dropdown">
                    <button
                        class="btn dashboard-profile-btn d-flex align-items-center gap-2"
                        type="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                    >
                        <span class="dashboard-profile-avatar">
                            {{ $adminInitials ?: 'CO' }}
                        </span>

                        <span class="dashboard-profile-copy d-none d-lg-flex">
                            <strong>{{ $adminName }}</strong>
                            <small>{{ $adminRole }}</small>
                        </span>

                        <i class="bi bi-chevron-down small d-none d-md-inline" aria-hidden="true"></i>
                    </button>

                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-4 dashboard-profile-menu">
                        <li class="px-3 py-2 border-bottom">
                            <div class="fw-bold">
                                {{ $adminName }}
                            </div>

                            <div class="small text-muted">
                                {{ $adminEmail }}
                            </div>

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

                        <li>
                            <hr class="dropdown-divider">
                        </li>

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
    </nav>
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
