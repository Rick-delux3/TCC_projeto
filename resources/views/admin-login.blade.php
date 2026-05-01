{{-- 
  Página de Login Administrativo
  
  Esta página permite que administradores da plataforma façam login usando CPF e senha.
  O layout segue padrão two-column com imagem motivacional à esquerda e formulário à direita.
  
  Dados enviados para: route('admin.login.post')
  Layout: layout-inicial.app
--}}

@extends('layout-inicial.app')

@section('content')
{{-- Container principal da página de autenticação --}}
<div class="admin-auth-page">
    {{-- Layout em dois painéis: imagem e formulário --}}
    <section class="admin-auth-shell">
        {{-- Seção visual esquerda com imagem e overlay --}}
        <aside class="admin-auth-aside">
            <img src="{{ asset('imgs/divulgar-imoveis-online-site-para-imobiliaria.jpg') }}" alt="Acesso administrativo">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="Logo NVS" class="auth-media-logo">
            <div class="admin-auth-overlay">
                <span class="admin-badge">Acesso Restrito</span>
                <h2>Gerencie a operacao interna da plataforma</h2>
                <p>
                    Entre com suas credenciais para acompanhar imobiliarias,
                    clientes e analises do fluxo administrativo.
                </p>
            </div>
        </aside>

        {{-- Seção direita com formulário de login --}}
        <div class="admin-auth-card">
            {{-- Cabeçalho do formulário --}}
            <header class="admin-auth-header">
                <span class="admin-auth-badge">Acesso Restrito</span>
                <h1>Login Administrativo</h1>
                <p>Informe CPF e senha para entrar no painel de administradores.</p>
            </header>

            {{-- Formulário de login administrativo --}}
            <form action="{{ route('admin.login.post') }}" method="POST" autocomplete="off" class="admin-auth-form">
                @csrf

                {{-- Campo CPF - entrada numérica validada --}}
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

                {{-- Campo senha - entrada protegida --}}
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

                {{-- Ações: botão de login e link para registro --}}
                <div class="admin-auth-actions">
                    {{-- Botão de login --}}
                    <button type="submit" class="admin-submit">Entrar</button>
                    {{-- Link para criar novo administrador --}}
                    <a href="{{ route('admin.register.form') }}" class="admin-outline-link">Criar Admin</a>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection
