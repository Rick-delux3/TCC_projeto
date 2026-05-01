{{-- 
  Pagina de Login - Usuario/Staff
  
  Formulario de login para usuarios regulares do sistema.
  Inclui campo de "lembrar acesso" e links para recuperacao de senha/registro.
  
  Dados enviados para: route('login')
  Layout: layout-inicial.app
--}}

@extends('layout-inicial.app')

@section('content')
<div class="client-auth-page">
    <section class="client-auth-shell">
        <aside class="client-auth-aside">
            <img src="{{ asset('imgs/segure-chave-a-mao-ao-ar-livre.jpg') }}" alt="Acesso do usuario">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="Logo NVS" class="auth-media-logo">

            <div class="client-auth-overlay">
                <span class="client-badge">Acesso interno</span>
                <h2>Entre na plataforma</h2>
                <p>
                    Acesse sua conta para continuar acompanhando dados,
                    operacoes e fluxos vinculados ao portal.
                </p>

                <div class="client-auth-points">
                    <span>Acesso protegido</span>
                    <span>Dados organizados</span>
                    <span>Operacao centralizada</span>
                </div>
            </div>
        </aside>

        <div class="client-auth-card">
            <header class="client-auth-header">
                <span class="client-auth-kicker">Login</span>
                <h1>Acessar conta</h1>
                <p>Informe seu e-mail e senha para entrar no sistema.</p>
            </header>

            @if (session('status'))
                <div class="password-reset-alert password-reset-alert--success" role="alert">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="client-auth-form">
                @csrf

                <div class="client-field">
                    <label for="email" class="client-label">E-mail</label>
                    <input
                        id="email"
                        class="client-input"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        placeholder="seuemail@exemplo.com"
                        required
                        autofocus
                        autocomplete="username"
                    >
                    @error('email')
                        <span class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="client-field">
                    <label for="password" class="client-label">Senha</label>
                    <div class="password-input-wrap">
                        <input
                            id="password"
                            class="client-input password-input"
                            type="password"
                            name="password"
                            placeholder="Sua senha de acesso"
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="password-toggle-button" data-toggle-password="password" aria-label="Mostrar senha">Ver</button>
                    </div>
                    @error('password')
                        <span class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <label for="remember_me" class="client-remember-row">
                    <input id="remember_me" type="checkbox" class="client-checkbox" name="remember">
                    <span>Lembrar acesso neste dispositivo</span>
                </label>

                @if (Route::has('password.request'))
                    <div class="client-recovery-box">
                        <span>Esqueceu sua senha?</span>
                        <a href="{{ route('password.request') }}">Redefinir senha</a>
                    </div>
                @endif

                <div class="client-actions">
                    <button type="submit" class="client-submit">Entrar</button>

                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="client-outline-link">Criar conta</a>
                    @endif
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
