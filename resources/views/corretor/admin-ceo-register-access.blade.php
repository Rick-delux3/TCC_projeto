@extends('layout-inicial.app')

@section('content')
<div class="admin-register-page">
    <section class="admin-register-shell">
        <aside class="admin-register-aside">
            <img
                src="{{ asset('imgs/divulgar-imoveis-online-site-para-imobiliaria.jpg') }}"
                alt="Acesso ao cadastro inicial do CEO"
            >

            <x-brand-logo class="auth-media-logo" />

            <div class="admin-register-overlay">
                <span class="admin-badge">Configuração inicial</span>

                <h2>Acesso restrito ao cadastro do CEO</h2>

                <p>
                    Informe a chave de configuração para liberar temporariamente
                    o cadastro do corretor principal.
                </p>
            </div>
        </aside>

        <div class="admin-register-card">
            <header class="admin-register-header">
                <span class="admin-register-kicker">Acesso sigiloso</span>

                <h1>Autorizar cadastro</h1>

                <p>
                    A chave não será incluída na URL nem mantida no formulário
                    de cadastro.
                </p>
            </header>

            <form
                action="{{ route('admin.ceo.register.authorize') }}"
                method="POST"
                autocomplete="off"
                class="admin-register-form"
            >
                @csrf

                <div class="admin-field">
                    <label for="key" class="admin-label">Chave de configuração</label>

                    <div class="password-input-wrap">
                        <input
                            type="password"
                            id="key"
                            name="key"
                            class="admin-input password-input"
                            placeholder="Informe a chave de acesso"
                            autocomplete="off"
                            required
                            autofocus
                        >
                    </div>

                    @error('key')
                        <span class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="admin-actions">
                    <button type="submit" class="admin-submit">
                        Continuar
                    </button>

                    <a href="{{ route('admin.login') }}" class="admin-outline-link">
                        Ir para login
                    </a>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
