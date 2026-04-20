@extends('layout-inicial.app')

@section('content')
<div class="client-register-page">
    <section class="client-register-shell client-register-shell--compact">
        <aside class="client-register-aside">
            <img src="{{ asset('imgs/seguro-fianca-locaticia_fundo_login_cadastro.png') }}" alt="Cadastro de usuario">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="Logo NVS" class="auth-media-logo">

            <div class="client-register-overlay">
                <span class="client-badge">Novo usuario</span>
                <h2>Crie seu acesso na plataforma</h2>
                <p>
                    Cadastre sua conta para acessar os recursos vinculados ao portal
                    e acompanhar os fluxos internos com seguranca.
                </p>

                <div class="client-register-points">
                    <span>Cadastro simples e seguro</span>
                    <span>Ambiente centralizado</span>
                    <span>Conta protegida por senha</span>
                </div>
            </div>
        </aside>

        <div class="client-register-card">
            <header class="client-register-header">
                <span class="client-register-kicker">Criar conta</span>
                <h1>Cadastro de usuario</h1>
                <p>Preencha os dados abaixo para criar seu acesso.</p>
            </header>

            <form method="POST" action="{{ route('register') }}" class="client-register-form">
                @csrf

                <div class="client-field">
                    <label for="name" class="client-label">Nome completo</label>
                    <input
                        id="name"
                        class="client-input"
                        type="text"
                        name="name"
                        value="{{ old('name') }}"
                        placeholder="Ex.: Maria Carolina Souza"
                        required
                        autofocus
                        autocomplete="name"
                    >
                    @error('name')
                        <span class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

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
                        autocomplete="username"
                    >
                    @error('email')
                        <span class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="client-grid">
                    <div class="client-field">
                        <label for="password" class="client-label">Senha</label>
                        <div class="password-input-wrap">
                            <input
                                id="password"
                                class="client-input password-input"
                                type="password"
                                name="password"
                                placeholder="Minimo de 8 caracteres"
                                required
                                autocomplete="new-password"
                            >
                            <button type="button" class="password-toggle-button" data-toggle-password="password" aria-label="Mostrar senha">Ver</button>
                        </div>
                        @error('password')
                            <span class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="client-field">
                        <label for="password_confirmation" class="client-label">Confirmar senha</label>
                        <div class="password-input-wrap">
                            <input
                                id="password_confirmation"
                                class="client-input password-input"
                                type="password"
                                name="password_confirmation"
                                placeholder="Repita a senha"
                                required
                                autocomplete="new-password"
                            >
                            <button type="button" class="password-toggle-button" data-toggle-password="password_confirmation" aria-label="Mostrar senha">Ver</button>
                        </div>
                    </div>
                </div>

                <div class="client-actions">
                    <button type="submit" class="client-submit">Cadastrar usuario</button>
                    <a href="{{ route('login') }}" class="client-outline-link">Entrar</a>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
