{{-- 
  Página de Login da Empresa/Imobiliária
  
  Formulário de login para empresas acessarem o portal CRM.
  Autenticação por email e senha com opção de recuperação de senha.
  
  Dados enviados para: route('empresa.login.post')
  Layout: layout-inicial.app
--}}

@extends('layout-inicial.app')

@section('content')
{{-- Container principal da página de login --}}
<div class="client-auth-page">
    <section class="client-auth-shell">
        <aside class="client-auth-aside">
            <img src="{{ asset('imgs/segure-chave-a-mao-ao-ar-livre.jpg') }}" alt="Acesso da imobiliaria">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="Logo NVS" class="auth-media-logo">

            <div class="client-auth-overlay">
                <span class="client-badge">Portal da imobiliaria</span>
                <h2>Acesse sua area CRM</h2>
                <p>
                    Entre com suas credenciais para acompanhar solicitacoes,
                    consultar resultados e seguir com sua operacao de locacao.
                </p>

                <div class="client-auth-points">
                    <span>Analises centralizadas</span>
                    <span>Acompanhamento de leads</span>
                    <span>Fluxo seguro para sua equipe</span>
                </div>
            </div>
        </aside>

        <div class="client-auth-card">
            <header class="client-auth-header">
                <span class="client-auth-kicker">Acesso ao portal</span>
                <h1>Login da empresa</h1>
                <p>Informe o e-mail e a senha cadastrados para continuar.</p>
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
                        autocomplete="username"
                        required
                    >
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
                        <button type="button" class="password-toggle-button" data-toggle-password="password" aria-label="Mostrar senha">Ver</button>
                    </div>
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
