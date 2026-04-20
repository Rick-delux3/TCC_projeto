@extends('layout-inicial.app')

@section('content')
<div class="password-reset-page">
    <section class="password-reset-shell password-reset-shell--compact">
        <aside class="password-reset-aside">
            <img src="{{ asset('imgs/segure-chave-a-mao-ao-ar-livre.jpg') }}" alt="Recuperacao de acesso da imobiliaria">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="Logo NVS" class="auth-media-logo">

            <div class="password-reset-overlay">
                <span class="client-badge">Portal da imobiliaria</span>
                <h2>Retome o acesso da sua empresa</h2>
                <p>
                    O link de redefinicao sera enviado para o e-mail de acesso
                    cadastrado no portal da imobiliaria.
                </p>

                <div class="password-reset-steps">
                    <span>1. Informe o e-mail da empresa</span>
                    <span>2. Acesse o link recebido</span>
                    <span>3. Cadastre uma nova senha</span>
                </div>
            </div>
        </aside>

        <div class="password-reset-card">
            <header class="password-reset-header">
                <span class="password-reset-kicker">Recuperacao de senha</span>
                <h1>Enviar link para a empresa</h1>
                <p>Digite o e-mail usado no cadastro da imobiliaria.</p>
            </header>

            @if (session('status'))
                <div class="password-reset-alert password-reset-alert--success" role="alert">
                    {{ session('status') }}
                </div>
            @endif

            @error('email')
                <div class="password-reset-alert password-reset-alert--danger" role="alert">
                    {{ $message }}
                </div>
            @enderror

            <form method="POST" action="{{ route('company.password.email') }}" class="password-reset-form">
                @csrf

                <div class="client-field">
                    <label for="email" class="client-label">E-mail cadastrado</label>
                    <input
                        type="email"
                        name="email"
                        id="email"
                        class="client-input"
                        value="{{ old('email') }}"
                        placeholder="contato@imobiliaria.com.br"
                        autocomplete="email"
                        required
                        autofocus
                    >
                </div>

                <button type="submit" class="client-submit">Enviar link de recuperacao</button>
                <a href="{{ route('empresa.login') }}" class="client-outline-link">Voltar para o login</a>
            </form>

            <p class="password-reset-note">
                Por seguranca, a mensagem pode levar alguns minutos. Confira sua caixa de entrada e spam.
            </p>
        </div>
    </section>
</div>
@endsection
