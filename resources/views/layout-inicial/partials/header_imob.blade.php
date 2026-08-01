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
@endphp

<header class="dashboard-client-header sticky-top">
    <nav class="navbar navbar-expand dashboard-client-navbar">
        <div class="container-fluid px-3 px-lg-4">

            {{-- Botão que abre o menu lateral vertical --}}
            <button
                class="btn dashboard-sidebar-toggle"
                type="button"
                data-bs-toggle="offcanvas"
                data-bs-target="#dashboardClientSidebar"
                aria-controls="dashboardClientSidebar"
                aria-label="Abrir navegação"
            >
                <i class="bi bi-list"></i>
            </button>

            {{-- Marca / logo --}}
            <a class="navbar-brand dashboard-client-brand ms-2" href="{{ route('company.dashboard') }}">
                <span class="dashboard-client-brand__logo">
                    <x-brand-logo />
                </span>

                <span class="dashboard-client-brand__text d-none d-md-flex">
                    <strong>{{ config('branding.profiles.'.config('branding.active', 'tcc').'.name', 'NVS Seguros') }}</strong>
                    <small>Painel da imobiliária</small>
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
                        <i class="bi bi-bell"></i>

                        @if ($notificationCount > 0)
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                {{ $notificationCount > 99 ? '99+' : $notificationCount }}
                            </span>
                        @endif
                    </button>

                    <div class="dropdown-menu dropdown-menu-end dashboard-notification-menu shadow border-0 rounded-4 p-0">
                        <div class="p-3 border-bottom">
                            <h6 class="fw-bold mb-1">Notificações</h6>
                            <p class="text-muted small mb-0">
                                Acompanhe os novos leads recebidos.
                            </p>
                        </div>

                        <div class="p-3">
                            @if ($notificationCount > 0)
                                <div class="d-flex gap-3 align-items-start">
                                    <span class="dashboard-notification-icon bg-primary-subtle text-primary">
                                        <i class="bi bi-person-plus"></i>
                                    </span>

                                    <div>
                                        <div class="fw-semibold">
                                            {{ $notificationCount }} lead(s) novo(s)
                                        </div>

                                        <div class="small text-muted">
                                            Existem leads em fase inicial aguardando acompanhamento.
                                        </div>

                                        <a href="{{ route('company.dashboard') }}#leads-section" class="small fw-semibold text-decoration-none">
                                            Ver leads
                                        </a>
                                    </div>
                                </div>
                            @else
                                <div class="text-center py-3">
                                    <i class="bi bi-check-circle text-success fs-4"></i>
                                    <p class="small text-muted mb-0 mt-2">
                                        Nenhuma nova notificação no momento.
                                    </p>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Perfil da imobiliária --}}
                <div class="dropdown">
                    <button
                        class="btn dashboard-profile-btn d-flex align-items-center gap-2"
                        type="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                    >
                        {{-- Avatar simples com iniciais --}}
                        <span class="dashboard-profile-avatar">
                            {{ $companyInitials ?: 'IM' }}
                        </span>

                        <span class="dashboard-profile-copy d-none d-lg-flex">
                            <strong>{{ $companyName }}</strong>
                            <small>{{ $companyEmail }}</small>
                        </span>

                        <i class="bi bi-chevron-down small d-none d-md-inline"></i>
                    </button>

                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-4 dashboard-profile-menu">
                        <li class="px-3 py-2 border-bottom">
                            <div class="fw-bold">{{ $companyName }}</div>
                            <div class="small text-muted">{{ $companyEmail }}</div>
                        </li>

                        <li>
                            <a class="dropdown-item py-2" href="{{ route('profile.edit') }}">
                                <i class="bi bi-gear me-2"></i>
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
                                <i class="bi bi-question-circle me-2"></i>
                                Tirar dúvidas
                            </a>
                        </li>

                        <li><hr class="dropdown-divider"></li>

                        <li>
                            <form method="POST" action="{{ route('empresa.logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item py-2 text-danger">
                                    <i class="bi bi-box-arrow-right me-2"></i>
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
    id="dashboardClientSidebar"
    aria-labelledby="dashboardClientSidebarLabel"
>
    <div class="offcanvas-header border-bottom">
        <div class="d-flex align-items-center gap-3">
            <span class="dashboard-sidebar-logo">
                <x-brand-logo />
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
                <i class="bi bi-grid-1x2"></i>
                <span>Dashboard</span>
            </a>

            <a href="{{ route('company.dashboard') }}#leads-section" class="dashboard-sidebar-link">
                <i class="bi bi-people"></i>
                <span>Leads</span>
            </a>

            <a href="{{ route('insurance-analyses.index') }}" class="dashboard-sidebar-link">
                <i class="bi bi-clipboard2-data"></i>
                <span>Análises</span>
            </a>

            <a href="{{ route('simulation.registered-company.access') }}" class="dashboard-sidebar-link">
                <i class="bi bi-link-45deg"></i>
                <span>Página de simulação</span>
            </a>

            <a href="{{ route('profile.edit') }}" class="dashboard-sidebar-link">
                <i class="bi bi-person-gear"></i>
                <span>Gerenciar conta</span>
            </a>

            <a
                href="https://api.whatsapp.com/send?phone=5511999999999&text=Ola,%20gostaria%20de%20tirar%20uma%20duvida"
                target="_blank"
                rel="noopener noreferrer"
                class="dashboard-sidebar-link"
            >
                <i class="bi bi-question-circle"></i>
                <span>Tirar dúvidas</span>
            </a>
        </nav>

        {{-- Área inferior do menu --}}
        <div class="dashboard-sidebar-footer mt-4 pt-3 border-top">
            <form method="POST" action="{{ route('empresa.logout') }}">
                @csrf
                <button type="submit" class="dashboard-sidebar-link dashboard-sidebar-link-danger border-0 w-100">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Sair</span>
                </button>
            </form>
        </div>
    </div>
</div>
