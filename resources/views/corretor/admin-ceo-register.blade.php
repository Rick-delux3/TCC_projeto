@extends('layout-inicial.app')

@section('content')
<div class="admin-register-page">
    <section class="admin-register-shell">
        <aside class="admin-register-aside">
            <img
                src="{{ asset('imgs/divulgar-imoveis-online-site-para-imobiliaria.jpg') }}"
                alt="Cadastro inicial do CEO"
            >

            <img
                src="{{ asset('imgs/Logo_NVS.png') }}"
                alt="Logo NVS"
                class="auth-media-logo"
            >

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

            @if (session('success'))
                <div class="alert alert-success">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('info'))
                <div class="alert alert-info">
                    {{ session('info') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form
                action="{{ route('admin.ceo.register.post') }}"
                method="POST"
                autocomplete="off"
                class="admin-register-form"
            >
                @csrf

                <input type="hidden" name="key" value="{{ request('key') }}">

                <div class="admin-field">
                    <label for="name" class="admin-label">Nome completo do CEO</label>

                    <input
                        type="text"
                        id="name"
                        name="name"
                        class="admin-input"
                        value="{{ old('name') }}"
                        placeholder="Ex.: Henrique Rodrigues Neves"
                        autocomplete="name"
                        required
                        autofocus
                    >

                    @error('name')
                        <span class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="admin-grid">
                    <div class="admin-field">
                        <label for="email" class="admin-label">E-mail principal</label>

                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="admin-input"
                            value="{{ old('email') }}"
                            placeholder="ceo@corretora.com.br"
                            autocomplete="username"
                            required
                        >

                        @error('email')
                            <span class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="admin-field">
                        <label for="cpf" class="admin-label">CPF</label>

                        <input
                            type="text"
                            id="cpf"
                            name="cpf"
                            class="admin-input"
                            value="{{ old('cpf') }}"
                            inputmode="numeric"
                            pattern="\d{11}"
                            maxlength="11"
                            placeholder="Digite apenas números"
                            required
                        >

                        @error('cpf')
                            <span class="client-field-error">{{ $message }}</span>
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
                                placeholder="Mínimo de 8 caracteres"
                                autocomplete="new-password"
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

                        <small class="admin-help-text">
                            Use uma senha forte com letras e números.
                        </small>

                        @error('password')
                            <span class="client-field-error">{{ $message }}</span>
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
                                required
                            >

                            <button
                                type="button"
                                class="password-toggle-button"
                                data-toggle-password="password_confirmation"
                                aria-label="Mostrar senha"
                            >
                                Ver
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

<script>
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const inputId = button.getAttribute('data-toggle-password');
            const input = document.getElementById(inputId);

            if (!input) {
                return;
            }

            const isPassword = input.type === 'password';

            input.type = isPassword ? 'text' : 'password';
            button.textContent = isPassword ? 'Ocultar' : 'Ver';
        });
    });
</script>
@endsection