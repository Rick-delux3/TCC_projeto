{{-- 
  Partial: Barra de Navegacao de Autenticacao
  
  Header minimalista para paginas de autenticacao (login, registro, etc).
  Elementos: link "Voltar para Inicio" com icone de seta.
  Aria labels para acessibilidade.
  
  Usa imagem: imgs/arrow.png
--}}

<header class="auth-topbar" aria-label="Navegacao de acesso">
    <div class="auth-topbar__inner">
        <a href="{{ route('index') }}" class="auth-topbar__back" aria-label="Voltar para a pagina inicial">
            <span class="auth-topbar__icon-frame" aria-hidden="true">
                <img src="{{ asset('imgs/arrow.png') }}" alt="" class="auth-topbar__back-icon">
            </span>
            <span class="auth-topbar__back-text">Inicio</span>
        </a>
    </div>
</header>
