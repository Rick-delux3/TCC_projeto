<header class="nvs-header">
    <div class="nvs-wrap nvs-bar">
        <a href="{{ route('index') }}" class="nvs-brand" aria-label="NVS Seguros — início">
            <picture>
                <source type="image/webp" srcset="{{ asset('imgs/nvs-marca-120.webp') }}">
                <x-brand-logo profile="tcc" alt="NVS" width="44" height="44" />
            </picture>
            <span>NVS <small>Seguros</small></span>
        </a>
        <nav aria-label="Navegação principal" class="nvs-nav flex items-center">
            <a href="#inicio">Início</a>
            <a href="#solucoes">Como funciona</a>
            <a href="#recursos">Acessos</a>
            <a href="#suporte">Dúvidas</a>
        </nav>
        <div class="nvs-logins flex items-center">
            <a href="{{ route('tcc.admin.access') }}" data-auth-access target="_blank" rel="noopener noreferrer" aria-label="Administração (abre em nova guia)" class="nvs-admin">Administração</a>
            <a href="{{ route('empresa.login') }}" data-auth-access target="_blank" rel="noopener noreferrer" aria-label="Entrar (abre em nova guia)" class="nvs-enter">Entrar</a>
        </div>
    </div>
</header>
