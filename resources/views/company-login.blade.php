@extends('layout-inicial.app')

@section('content')
<div class="client-auth-page">
    <section class="client-auth-shell">
        <aside class="client-auth-aside">
            <img src="{{ asset('imgs/img-cadastro.jpeg') }}" alt="Acesso do cliente">
            <div class="client-auth-overlay">
                <span class="client-badge">Portal do Cliente</span>
                <h2>Acesse sua area CRM</h2>
                <p>
                    Entre com suas credenciais para acompanhar solicitações,
                    consultar resultados e seguir com sua operação de locacao.
                </p>
            </div>
        </aside>

        <div class="client-auth-card">
            <header class="client-auth-header">
                <h1>Login Empresa</h1>
                <p>Informe seu e-mail e senha para continuar.</p>
            </header>

            <form action="{{ route('empresa.login.post') }}" method="POST" autocomplete="off" class="client-auth-form">
                @csrf

                <div class="client-field">
                    <label for="email" class="client-label">E-mail</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="client-input"
                        value="{{ old('email') }}"
                        placeholder="contato@imobiliaria.com.br"
                        required
                    >
                </div>

                <div class="client-field">
                    <label for="password" class="client-label">Senha</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="client-input"
                        placeholder="Sua senha de acesso"
                        required
                    >
                </div>

                <div class="client-recovery-box">
                    <span>Esqueceu sua senha?</span>
                    <a href="{{ route('company.password.request') }}">Redefinir senha</a>
                </div>

                <div class="client-actions">
                    <button type="submit" class="client-submit">Entrar</button>
                    <a href="{{ route('empresa.register.form') }}" class="client-outline-link">Cadastrar imobiliaria</a>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
