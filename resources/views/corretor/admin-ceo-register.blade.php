@extends('layout-inicial.app')

@section('content')
<div class="admin-register-page">
    <section class="admin-register-shell">
        <aside class="admin-register-aside">
            <img
                src="{{ asset('imgs/divulgar-imoveis-online-site-para-imobiliaria.jpg') }}"
                alt="Cadastro inicial do CEO"
            >

            <x-brand-logo class="auth-media-logo" />

            <div class="admin-register-overlay">
                <span class="admin-badge">Configuração inicial</span>

                <h2>Cadastro único do CEO da corretora</h2>

                <p>
                    Este formulário cria o corretor principal da plataforma.
                    Após a criação do CEO, esta tela será bloqueada automaticamente.
                </p>

                <div class="admin-register-points">
                    <span>Acesso máximo ao painel administrativo</span>
                    <span>Responsável pela organização da equipe</span>
                    <span>Controle futuro de cargos, permissões e configurações</span>
                </div>
            </div>
        </aside>

        <div class="admin-register-card">
            <header class="admin-register-header">
                <span class="admin-register-kicker">Acesso sigiloso</span>

                <h1>Cadastrar CEO</h1>

                <p>
                    Preencha os dados do corretor principal. Esse cadastro só pode
                    ser realizado uma única vez.
                </p>
            </header>

            @if (session('info'))
                <div class="alert alert-info">
                    {{ session('info') }}
                </div>
            @endif

            <form
                action="{{ route('admin.ceo.register.post') }}"
                method="POST"
                autocomplete="on"
                class="admin-register-form"
            >
                @csrf

                <div class="client-honeypot" aria-hidden="true">
                    <label for="website">Não preencha este campo</label>
                    <input
                        type="text"
                        id="website"
                        name="website"
                        value=""
                        tabindex="-1"
                        autocomplete="off"
                    >
                </div>

                <div class="admin-field">
                    <label for="name" class="admin-label">Nome completo do CEO</label>

                    <div class="admin-input-wrap">
                        <span class="admin-input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M4 21a8 8 0 0 1 16 0"/>
                            </svg>
                        </span>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            class="admin-input admin-input--with-icon @error('name') is-invalid @enderror"
                            value="{{ old('name') }}"
                            placeholder="Ex.: Henrique Rodrigues Neves"
                            autocomplete="name"
                            maxlength="255"
                            @error('name') aria-describedby="name-error" @enderror
                            required
                            autofocus
                        >
                    </div>

                    @error('name')
                        <span id="name-error" class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="admin-grid">
                    <div class="admin-field">
                        <label for="email" class="admin-label">E-mail principal</label>

                        <div class="admin-input-wrap">
                            <span class="admin-input-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/>
                                    <path d="m22 7-10 6L2 7"/>
                                </svg>
                            </span>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="admin-input admin-input--with-icon @error('email') is-invalid @enderror"
                                value="{{ old('email') }}"
                                placeholder="ceo@corretora.com.br"
                                autocomplete="email"
                                maxlength="255"
                                @error('email') aria-describedby="email-error" @enderror
                                required
                            >
                        </div>

                        @error('email')
                            <span id="email-error" class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>

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
                                @error('cpf') aria-describedby="cpf-error" @enderror
                                required
                            >
                        </div>

                        @error('cpf')
                            <span id="cpf-error" class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div class="admin-grid">
                    <div class="admin-field">
                        <label for="password" class="admin-label">Senha de acesso</label>

                        <div class="password-input-wrap">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="admin-input password-input"
                                placeholder="Crie uma senha segura"
                                autocomplete="new-password"
                                minlength="8"
                                maxlength="72"
                                aria-describedby="password-requirements @error('password') password-error @enderror"
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

                        <small id="password-requirements" class="admin-help-text">
                            Use de 8 a 72 caracteres, com letras e números.
                        </small>

                        @error('password')
                            <span id="password-error" class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="admin-field">
                        <label for="password_confirmation" class="admin-label">
                            Confirmar senha
                        </label>

                        <div class="password-input-wrap">
                            <input
                                type="password"
                                id="password_confirmation"
                                name="password_confirmation"
                                class="admin-input password-input"
                                placeholder="Repita a senha informada"
                                autocomplete="new-password"
                                minlength="8"
                                maxlength="72"
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle-button"
                                data-toggle-password="password_confirmation"
                                aria-label="Mostrar confirmação de senha"
                                aria-pressed="false"
                                title="Mostrar confirmação de senha"
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
                    </div>
                </div>

                <div class="admin-actions">
                    <button type="submit" class="admin-submit">
                        Cadastrar CEO
                    </button>

                    <a href="{{ route('admin.login') }}" class="admin-outline-link">
                        Ir para login
                    </a>
                </div>

                <div class="admin-auth-note">
                    <small>
                        Após concluir este cadastro, a rota de criação do CEO será
                        fechada automaticamente pelo sistema.
                    </small>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
