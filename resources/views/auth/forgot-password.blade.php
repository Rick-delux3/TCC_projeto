{{-- 
  Pagina de Recuperacao de Senha - Usuario
  
  Formulario para solicitar reset de senha.
  Envia link de redefinicao para email cadastrado.
  Mostra mensagens de sucesso/erro com estilos dedicados.
  
  Dados enviados para: route('password.email')
  Layout: layout-inicial.app
--}}

@extends('layout-inicial.app')

@section('content')
<div class="password-reset-page">
    <section class="password-reset-shell password-reset-shell--compact">
        <aside class="password-reset-aside">
            <img src="{{ asset('imgs/segure-chave-a-mao-ao-ar-livre.jpg') }}" alt="Recuperacao de senha">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="Logo NVS" class="auth-media-logo">

            <div class="password-reset-overlay">
                <span class="client-badge">Acesso seguro</span>
                <h2>Recupere sua senha sem complicacao</h2>
                <p>
                    Enviaremos um link seguro para o e-mail cadastrado. Depois,
                    basta criar uma nova senha e voltar para a plataforma.
                </p>

                <div class="password-reset-steps">
                    <span>1. Informe o e-mail</span>
                    <span>2. Abra o link recebido</span>
                    <span>3. Defina a nova senha</span>
                </div>
            </div>
        </aside>

        <div class="password-reset-card">
            <header class="password-reset-header">
                <span class="password-reset-kicker">Recuperacao de senha</span>
                <h1>Enviar link de redefinicao</h1>
                <p>Use o e-mail cadastrado para receber as instrucoes de acesso.</p>
            </header>

            @if (session('status'))
                <div class="password-reset-alert password-reset-alert--success" role="alert">
                    {{ session('status') }}
                </div>
            @endif

            @error('email')
                <div class="password-reset-alert password-reset-alert--danger" role="alert">
                    {{ $message }}
                </div>
            @enderror

            <form method="POST" action="{{ route('password.email') }}" class="password-reset-form">
                @csrf

                <div class="client-field">
                    <label for="email" class="client-label">E-mail cadastrado</label>
                    <input
                        type="email"
                        name="email"
                        id="email"
                        class="client-input"
                        value="{{ old('email') }}"
                        placeholder="seuemail@exemplo.com"
                        autocomplete="email"
                        required
                        autofocus
                    >
                </div>

                <button type="submit" class="client-submit">Enviar link de recuperacao</button>
                <a href="{{ route('login') }}" class="client-outline-link">Voltar para o login</a>
            </form>

            <p class="password-reset-note">
                Se o e-mail existir em nossa base, voce recebera a mensagem em alguns instantes.
                Confira tambem a pasta de spam.
            </p>
        </div>
    </section>
</div>
@endsection
