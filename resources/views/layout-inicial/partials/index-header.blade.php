<header class="index-header">
    <div class="container">
        <div class="index-header-wrap">
            <a href="{{ route('index') }}" class="index-brand" aria-label="Ir para a pagina inicial">
                <img src="{{ asset('imgs/logo-header.jpg') }}" alt="AkiAluga">
                <div>
                    <strong>AkiAluga</strong>
                    <span>Plataforma de seguro fianca</span>
                </div>
            </a>

            <button
                class="index-menu-toggle d-lg-none"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#indexMenu"
                aria-controls="indexMenu"
                aria-expanded="false"
                aria-label="Abrir menu"
            >
                <i class="bi bi-list"></i>
            </button>
        </div>

        <div class="collapse d-lg-block index-nav-collapse" id="indexMenu">
            <nav class="index-nav-links" aria-label="Navegacao principal">
                <a href="{{ route('empresa.login') }}" class="index-link">
                    <i class="bi bi-box-arrow-in-right"></i>
                    Login Imobiliaria
                </a>
                <a href="{{ route('empresa.register.form') }}" class="index-cta-client">
                    <i class="bi bi-building-add"></i>
                    Cadastro de Clientes
                </a>
            </nav>
        </div>
    </div>
</header>
