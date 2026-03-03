@extends('layout-inicial.app')

@section('content')
<div class="admin-auth-page">
    <section class="admin-auth-card">
        <header class="admin-auth-header">
            <span class="admin-auth-badge">Acesso Restrito</span>
            <h1>Login Administrativo</h1>
            <p>Informe CPF e senha para entrar no painel de administradores.</p>
        </header>

        <form action="{{ route('admin.login.post') }}" method="POST" autocomplete="off" class="admin-auth-form">
            @csrf

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
                    placeholder="Digite apenas numeros"
                    required
                >
            </div>

            <div class="admin-field">
                <label for="password" class="admin-label">Senha</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="admin-input"
                    placeholder="Sua senha de acesso"
                    required
                >
            </div>

            <div class="admin-auth-actions">
                <button type="submit" class="admin-submit">Entrar</button>
                <a href="{{ route('admin.register.form') }}" class="admin-outline-link">Criar Admin</a>
            </div>
        </form>
    </section>
</div
@endsection
