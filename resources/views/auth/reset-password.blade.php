{{-- 
  Pagina de Reset de Senha - Usuario
  
  Formulario para usuario redefinir senha apos solicitar recuperacao.
  Recebe token de email e email atraves da URL.
  Campos: email (pre-preenchido), nova senha, confirmacao.
  Display de erros com estilos dedicados.
  
  Endpoint: route('password.store') - POST
  Layout: layout-inicial.app
--}}

@extends('layout-inicial.app')

@section('content')
<div class="password-reset-page">
    <section class="password-reset-shell">
        <aside class="password-reset-aside">
            <img src="{{ asset('imgs/segure-chave-a-mao-ao-ar-livre.jpg') }}" alt="Nova senha">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" alt="Logo NVS" class="auth-media-logo">

            <div class="password-reset-overlay">
                <span class="client-badge">Nova senha</span>
                <h2>Crie uma senha forte para continuar</h2>
                <p>
                    Escolha uma senha segura e confirme os dados do e-mail que recebeu
                    o link de recuperacao.
                </p>

                <div class="password-reset-steps">
                    <span>Use letras, numeros e simbolos</span>
                    <span>Evite senhas usadas em outros sistemas</span>
                    <span>Guarde seu acesso com seguranca</span>
                </div>
            </div>
        </aside>

        <div class="password-reset-card">
            <header class="password-reset-header">
                <span class="password-reset-kicker">Redefinicao de acesso</span>
                <h1>Definir nova senha</h1>
                <p>Informe o e-mail do cadastro e escolha sua nova senha.</p>
            </header>

            @if ($errors->any())
                <div class="password-reset-alert password-reset-alert--danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.store') }}" class="password-reset-form">
                @csrf
                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div class="client-field">
                    <label for="email" class="client-label">E-mail</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        class="client-input"
                        value="{{ old('email', $request->email) }}"
                        placeholder="seuemail@exemplo.com"
                        required
                        autocomplete="username"
                        autofocus
                    >
                </div>

                <div class="client-field">
                    <label for="password" class="client-label">Nova senha</label>
                    <div class="password-input-wrap">
                        <input
                            id="password"
                            type="password"
                            name="password"
                            class="client-input password-input"
                            placeholder="Digite a nova senha"
                            required
                            autocomplete="new-password"
                        >
                        <button type="button" class="password-toggle-button" data-toggle-password="password" aria-label="Mostrar senha">Ver</button>
                    </div>
                </div>

                <div class="client-field">
                    <label for="password_confirmation" class="client-label">Confirmar nova senha</label>
                    <div class="password-input-wrap">
                        <input
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            class="client-input password-input"
                            placeholder="Repita a nova senha"
                            required
                            autocomplete="new-password"
                        >
                        <button type="button" class="password-toggle-button" data-toggle-password="password_confirmation" aria-label="Mostrar senha">Ver</button>
                    </div>
                </div>

                <button type="submit" class="client-submit">Salvar nova senha</button>
                <a href="{{ route('login') }}" class="client-outline-link">Voltar para o login</a>
            </form>
        </div>
    </section>
</div>
@endsection
