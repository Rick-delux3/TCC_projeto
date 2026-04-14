<header class="dashboard-header">
    <nav class="navbar navbar-expand-lg dashboard-navbar">
        <div class="container dashboard-navbar__inner">
            <a class="navbar-brand dashboard-brand" href="{{ route('Dashboard') }}">
                <span class="dashboard-brand__mark">
                    <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="NVS Seguros" class="navbar-brand__logo">
                </span>

                <span class="dashboard-brand__copy d-none d-sm-flex">
                    <strong>NVS Seguros</strong>
                    <small>Painel do cliente</small>
                </span>
            </a>

            <button
                class="navbar-toggler dashboard-navbar__toggler"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#navbarMenu"
                aria-controls="navbarMenu"
                aria-expanded="false"
                aria-label="Abrir menu"
            >
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse dashboard-nav-shell" id="navbarMenu">
                <ul class="navbar-nav ms-auto align-items-lg-center dashboard-nav-list">
                    <li class="nav-item">
                        <a class="nav-link dashboard-nav-link" href="{{ route('empresa.logout') }}">Sair</a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link dashboard-nav-link" href="#">Companhias</a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link dashboard-nav-link" href="#">Quem somos?</a>
                    </li>

                    <li class="nav-item ms-lg-2 mt-3 mt-lg-0">
                        <a
                            href="https://api.whatsapp.com/send?phone=5511999999999&text=Ola,%20gostaria%20de%20ajuda"
                            class="dashboard-header__cta"
                        >
                            Conversar com o corretor
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
</header>
