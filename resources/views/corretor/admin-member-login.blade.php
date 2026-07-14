@extends('layout-inicial.app')

@section('content')
<div class="client-auth-page">
    <section class="client-auth-shell">
        <aside class="client-auth-aside">
            <img src="{{ asset('imgs/segure-chave-a-mao-ao-ar-livre.jpg') }}" alt="Acesso do corretor integrante">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="Logo NVS" class="auth-media-logo">

            <div class="client-auth-overlay">
                <span class="client-badge">Portal dos corretores</span>

                <h2>Acesse o painel da corretora</h2>

                <p>
                    Entre como integrante para acompanhar clientes, visualizar leads,
                    consultar análises e seguir com as operações liberadas pelo CEO.
                </p>

                <div class="client-auth-points">
                    <span>Clientes das imobiliárias</span>
                    <span>Análises centralizadas</span>
                    <span>Acesso seguro por equipe</span>
                </div>
            </div>
        </aside>

        <div class="client-auth-card">
            <header class="client-auth-header">
                <span class="client-auth-kicker">Acesso do integrante</span>

                <h1>Login do corretor integrante</h1>

                <p>
                    Informe o e-mail cadastrado pelo CEO da corretora e sua senha de acesso.
                </p>
            </header>

            <form
                action="{{ route('admin.member.login.post') }}"
                method="POST"
                autocomplete="off"
                class="client-auth-form"
            >
                @csrf

                <div class="client-field">
                    <label for="email" class="client-label">E-mail</label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="client-input"
                        value="{{ old('email', request('email')) }}"
                        placeholder="integrante@corretora.com.br"
                        autocomplete="username"
                        required
                        autofocus
                    >

                    @error('email')
                        <div class="text-danger small mt-1">{{ $message }}</div>
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
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle-button"
                            data-toggle-password="password"
                            aria-label="Mostrar senha"
                        >
                            Ver
                        </button>
                    </div>

                    @error('password')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <div class="client-recovery-box">
                    <span>Acesso administrativo da corretora?</span>
                    <a href="{{ route('admin.ceo.login') }}">Sou CEO</a>
                </div>

                <div class="client-actions">
                    <button type="submit" class="client-submit">
                        Entrar no painel
                    </button>

                    <a href="{{ route('index') }}" class="client-outline-link">
                        Voltar para o início
                    </a>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
