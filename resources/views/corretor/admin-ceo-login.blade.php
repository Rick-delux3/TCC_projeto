@extends('layout-inicial.app')

@section('content')
<div class="admin-auth-page">
    <section class="admin-auth-shell">
        <aside class="admin-auth-aside">
            <img
                src="{{ asset('imgs/divulgar-imoveis-online-site-para-imobiliaria.jpg') }}"
                alt="Acesso restrito do CEO"
            >

            <img
                src="{{ asset('imgs/Logo_NVS.png') }}"
                alt="Logo NVS"
                class="auth-media-logo"
            >

            <div class="admin-auth-overlay">
                <span class="admin-badge">Acesso Sigiloso</span>

                <h2>Área exclusiva do CEO da corretora</h2>

                <p>
                    Este acesso é reservado ao corretor principal responsável
                    pela administração da plataforma, organização da equipe
                    e gestão das operações internas.
                </p>
            </div>
        </aside>

        <div class="admin-auth-card">
            <header class="admin-auth-header">
                <span class="admin-auth-badge">CEO</span>

                <h1>Login do CEO</h1>

                <p>
                    Informe CPF e senha para acessar o painel principal da corretora.
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
                action="{{ route('admin.ceo.login.post') }}"
                method="POST"
                autocomplete="off"
                class="admin-auth-form"
            >
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
                        placeholder="Digite apenas números"
                        required
                        autofocus
                    >

                    @error('cpf')
                        <span class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="admin-field">
                    <label for="password" class="admin-label">Senha</label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="admin-input"
                        placeholder="Sua senha de acesso"
                        autocomplete="current-password"
                        required
                    >

                    @error('password')
                        <span class="client-field-error">{{ $message }}</span>
                    @enderror
                </div>

                <div class="admin-auth-actions">
                    <button type="submit" class="admin-submit">
                        Entrar
                    </button>
                </div>

                <div class="admin-auth-note">
                    <small>
                        O cadastro do CEO é feito apenas uma vez por acesso sigiloso.
                        Após o cadastro inicial, somente o login permanece disponível.
                    </small>
                </div>
            </form>
        </div>
    </section>
</div>
@endsection