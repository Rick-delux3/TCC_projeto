@extends('layout-inicial.app')

@section('content')
<div class="admin-auth-page">
    <section class="admin-auth-shell">
        <aside class="admin-auth-aside">
            <img
                src="{{ asset('imgs/divulgar-imoveis-online-site-para-imobiliaria.jpg') }}"
                alt="Acesso restrito do CEO"
            >

            <img
                src="{{ asset('imgs/Logo_NVS.png') }}"
                alt="Logo NVS"
                class="auth-media-logo"
            >

            <div class="admin-auth-overlay">
                <span class="admin-badge">Acesso Sigiloso</span>

                <h2>Área exclusiva do CEO da corretora</h2>

                <p>
                    Este acesso é reservado ao corretor principal responsável
                    pela administração da plataforma, organização da equipe
                    e gestão das operações internas.
                </p>
            </div>
        </aside>

        <div class="admin-auth-card">
            <header class="admin-auth-header">
                <span class="admin-auth-badge">CEO</span>

                <h1>Login do CEO</h1>

                <p>
                    Informe CPF e senha para acessar o painel principal da corretora.
                </p>
            </header>

            @if (session('info'))
                <div class="alert alert-info">
                    {{ session('info') }}
                </div>
            @endif

            <form
                action="{{ route('admin.ceo.login.post') }}"
                method="POST"
                autocomplete="on"
                class="admin-auth-form"
            >
                @csrf

                <div class="admin-field">
                    <label for="cpf" class="admin-label">CPF</label>

                    <div class="admin-input-wrap">
                        <span class="admin-input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <rect x="3" y="5" width="18" height="14" rx="2"/>
                                <circle cx="8" cy="11" r="2"/>
                                <path d="M5.5 16a3 3 0 0 1 5 0M14 10h4M14 14h4"/>
                            </svg>
                        </span>
                        <input
                            type="text"
                            id="cpf"
                            name="cpf"
                            class="admin-input admin-input--with-icon @error('cpf') is-invalid @enderror"
                            value="{{ old('cpf') }}"
                            inputmode="numeric"
                            pattern="\d{11}"
                            maxlength="11"
                            placeholder="Digite apenas números"
                            autocomplete="username"
                            @error('cpf') aria-describedby="login-cpf-error" @enderror
                            required
                            autofocus
                        >
                    </div>

                    @error('cpf')
                        <span id="login-cpf-error" class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="admin-field">
                    <label for="password" class="admin-label">Senha</label>

                    <div class="password-input-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="admin-input password-input @error('password') is-invalid @enderror"
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

                <div class="admin-auth-actions">
                    <button type="submit" class="admin-submit">
                        Entrar
                    </button>
                </div>

                <div class="admin-auth-note">
                    <small>
                        O cadastro do CEO é feito apenas uma vez por acesso sigiloso.
                        Após o cadastro inicial, somente o login permanece disponível.
                    </small>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
