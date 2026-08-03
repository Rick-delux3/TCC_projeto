@extends('layout-inicial.app')

@section('content')
<div class="client-auth-page">
    <section class="client-auth-shell">
        <aside class="client-auth-aside">
            <img src="{{ asset('imgs/segure-chave-a-mao-ao-ar-livre.jpg') }}" alt="Acesso da imobiliária">
            <x-brand-logo class="auth-media-logo" />

            <div class="client-auth-overlay">
                <span class="client-badge">Portal da imobiliária</span>
                <h2>Acesse sua área CRM</h2>
                <p>
                    Entre com suas credenciais para acompanhar solicitações,
                    consultar resultados e seguir com sua operação de locação.
                </p>

                <div class="client-auth-points">
                    <span>Análises centralizadas</span>
                    <span>Acompanhamento de leads</span>
                    <span>Fluxo seguro para sua equipe</span>
                </div>
            </div>
        </aside>

        <div class="client-auth-card">
            <header class="client-auth-header">
                <span class="client-auth-kicker">Acesso ao portal</span>
                <h1>Login da imobiliária</h1>
                <p>Informe o e-mail e a senha cadastrados para continuar.</p>
            </header>

            <form action="{{ route('empresa.login.post') }}" method="POST" autocomplete="on" class="client-auth-form">
                @csrf

                <div class="client-field">
                    <label for="email" class="client-label">E-mail</label>
                    <div class="client-input-wrap">
                        <span class="client-input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <path d="M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/>
                                <path d="m22 7-10 6L2 7"/>
                            </svg>
                        </span>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="client-input client-input--with-icon @error('email') is-invalid @enderror"
                            value="{{ old('email') }}"
                            placeholder="contato@imobiliaria.com.br"
                            autocomplete="username"
                            maxlength="255"
                            @error('email') aria-describedby="login-email-error" @enderror
                            required
                        >
                    </div>
                    @error('email')
                        <span id="login-email-error" class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="client-field">
                    <label for="password" class="client-label">Senha</label>
                    <div class="password-input-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="client-input password-input"
                            placeholder="Sua senha de acesso"
                            autocomplete="current-password"
                            maxlength="72"
                            @error('password') aria-describedby="login-password-error" @enderror
                            required
                        >
                        <button
                            type="button"
                            class="password-toggle-button"
                            data-toggle-password="password"
                            aria-label="Mostrar senha"
                            aria-pressed="false"
                            title="Mostrar senha"
                        >
                            <svg data-password-icon="show" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg data-password-icon="hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" hidden>
                                <path d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M9.9 4.2A10.8 10.8 0 0 1 12 4c6.5 0 10 8 10 8a18 18 0 0 1-2.1 3.2M6.6 6.6C3.6 8.5 2 12 2 12s3.5 8 10 8a10.8 10.8 0 0 0 4.1-.8"/>
                            </svg>
                        </button>
                    </div>
                    @error('password')
                        <span id="login-password-error" class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="client-recovery-box">
                    <span>Esqueceu sua senha?</span>
                    <a href="{{ route('company.password.request') }}">Redefinir senha</a>
                </div>

                <div class="client-actions">
                    <button type="submit" class="client-submit">Entrar</button>
                    <a href="{{ route('empresa.register.form') }}" class="client-outline-link">Cadastrar imobiliária</a>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
