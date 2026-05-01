{{-- 
  Página de Registro de Administrador
  
  Formulário para cadastro de novos administradores da plataforma.
  Inclui validação de email, CPF e confirmação de senha.
  
  Dados enviados para: route('admin.register.post')
  Layout: layout-inicial.app
--}}

@extends('layout-inicial.app')

@section('content')
{{-- Container principal da página de registro --}}
<div class="admin-register-page">
    <section class="admin-register-shell">
        <aside class="admin-register-aside">
            <img src="{{ asset('imgs/divulgar-imoveis-online-site-para-imobiliaria.jpg') }}" alt="Equipe administrativa de corretora">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="Logo NVS" class="auth-media-logo">

            <div class="admin-register-overlay">
                <span class="admin-badge">Area administrativa</span>
                <h2>Painel de cadastro de corretores administradores</h2>
                <p>
                    Este acesso e exclusivo para cadastro interno de administradores
                    responsaveis por acompanhar imobiliarias, clientes e analises.
                </p>

                <div class="admin-register-points">
                    <span>Controle de tags e organizacao de carteiras</span>
                    <span>Visibilidade centralizada das analises enviadas</span>
                    <span>Gestao de relacionamento com imobiliarias</span>
                </div>
            </div>
        </aside>

        <div class="admin-register-card">
            <header class="admin-register-header">
                <span class="admin-register-kicker">Acesso restrito</span>
                <h1>Cadastrar administrador</h1>
                <p>Preencha os dados abaixo para criar um novo acesso administrativo.</p>
            </header>

            <form action="{{ route('admin.register.post') }}" method="POST" autocomplete="off" class="admin-register-form">
                @csrf

                <div class="admin-field">
                    <label for="name" class="admin-label">Nome completo</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        class="admin-input"
                        value="{{ old('name') }}"
                        placeholder="Ex.: Maria Carolina Souza"
                        required
                    >
                    @error('name')
                        <span class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="admin-grid">
                    <div class="admin-field">
                        <label for="email" class="admin-label">E-mail corporativo</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            class="admin-input"
                            value="{{ old('email') }}"
                            placeholder="admin@corretora.com.br"
                            autocomplete="username"
                            required
                        >
                        @error('email')
                            <span class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="admin-field">
                        <label for="cpf" class="admin-label">CPF (somente numeros)</label>
                        <input
                            type="text"
                            id="cpf"
                            name="cpf"
                            class="admin-input"
                            value="{{ old('cpf') }}"
                            inputmode="numeric"
                            pattern="\d{11}"
                            maxlength="11"
                            placeholder="00000000000"
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
                                placeholder="Minimo de 6 caracteres"
                                autocomplete="new-password"
                                required
                            >
                            <button type="button" class="password-toggle-button" data-toggle-password="password" aria-label="Mostrar senha">Ver</button>
                        </div>
                        @error('password')
                            <span class="client-field-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="admin-field">
                        <label for="password_confirmation" class="admin-label">Confirmar senha</label>
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
                            <button type="button" class="password-toggle-button" data-toggle-password="password_confirmation" aria-label="Mostrar senha">Ver</button>
                        </div>
                    </div>
                </div>

                <div class="admin-actions">
                    <button type="submit" class="admin-submit">Cadastrar administrador</button>
                    <a href="{{ route('admin.login') }}" class="admin-outline-link">Entrar</a>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
