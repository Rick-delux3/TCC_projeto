@extends('layout-inicial.app')

@section('content')
<div class="admin-register-page">
    <section class="admin-register-shell">
        <aside class="admin-register-aside">
            <img src="{{ asset('imgs/divulgar-imoveis-online-site-para-imobiliaria.jpg') }}" alt="Equipe administrativa de corretora">
            <div class="admin-register-overlay">
                <span class="admin-badge">Area Administrativa</span>
                <h2>Painel de Cadastro de Corretores Administradores</h2>
                <p>
                    Este acesso e exclusivo para cadastro interno de administradores responsaveis
                    por acompanhar imobiliarias, clientes e analises de seguro fianca.
                </p>
                <ul>
                    <li>Controle de tags e organizacao de carteiras.</li>
                    <li>Visibilidade centralizada das analises enviadas.</li>
                    <li>Gestao de relacionamento com imobiliarias cadastradas.</li>
                </ul>
            </div>
        </aside>

        <div class="admin-register-card">
            <header class="admin-register-header">
                <h1>Cadastrar Administrador</h1>
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
                            required
                        >
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
                    </div>
                </div>

                <div class="admin-grid">
                    <div class="admin-field">
                        <label for="password" class="admin-label">Senha de acesso</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="admin-input"
                            placeholder="Minimo de 6 caracteres"
                            required
                        >
                    </div>

                    <div class="admin-field">
                        <label for="password_confirmation" class="admin-label">Confirmar senha</label>
                        <input
                            type="password"
                            id="password_confirmation"
                            name="password_confirmation"
                            class="admin-input"
                            placeholder="Repita a senha informada"
                            required
                        >
                    </div>
                </div>

                <div class="admin-actions">
                    <button type="submit" class="admin-submit">Cadastrar administrador</button>
                    <a href="{{ route('admin.login') }}" class="admin-login-link">Ja possuo acesso administrativo</a>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
