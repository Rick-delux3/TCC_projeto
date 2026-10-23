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
            href="{{ route('company.dashboard') }}#leads-section"
            @if (request()->routeIs('insurance-analyses.*')) aria-current="page" @endif
        >
            Análises
        </a>
    @endif

    <a class="dashboard-header-nav__link" href="{{ $simulationRoute }}">
        Simulação
    </a>
</nav>
