@extends('layout-inicial.app')

@section('body-class', config('branding.active', 'tcc') === 'tcc' ? 'tcc-company-auth-body' : '')
@section('main-class', config('branding.active', 'tcc') === 'tcc' ? 'tcc-company-auth-main' : '')

@section('content')
@php($isTcc = config('branding.active', 'tcc') === 'tcc')
<div @class(['password-reset-page', 'tcc-company-auth' => $isTcc])>
    <section @class(['password-reset-shell', 'client-auth-shell' => $isTcc])>
        <aside @class(['password-reset-aside', 'client-auth-aside' => $isTcc])>
            <img src="{{ asset('imgs/segure-chave-a-mao-ao-ar-livre.jpg') }}" alt="Nova senha da imobiliaria">
            <x-brand-logo class="auth-media-logo" />

            <div @class(['password-reset-overlay', 'client-auth-overlay' => $isTcc])>
                <span class="client-badge">Portal da imobiliaria</span>
                <h2>Defina a nova senha da empresa</h2>
                <p>
                    Essa senha sera usada para acessar o painel CRM e acompanhar
                    as analises de seguro fianca da imobiliaria.
                </p>

                <div @class(['password-reset-steps', 'client-auth-points' => $isTcc])>
                    <span>Confirme o e-mail da empresa</span>
                    <span>Escolha uma senha forte</span>
                    <span>Volte para o login do portal</span>
                </div>
            </div>
        </aside>

        <div @class(['password-reset-card', 'client-auth-card' => $isTcc])>
            <header @class(['password-reset-header', 'client-auth-header' => $isTcc])>
                <span @class(['password-reset-kicker', 'client-auth-kicker' => $isTcc])>Redefinicao de acesso</span>
                <h1>Nova senha da imobiliaria</h1>
                <p>Preencha os dados abaixo para concluir a recuperacao.</p>
            </header>

            @if ($errors->any())
                <div class="password-reset-alert password-reset-alert--danger" role="alert">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('company.password.store') }}" class="password-reset-form">
                @csrf
                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div class="client-field">
                    <label for="email" class="client-label">E-mail da empresa</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        class="client-input"
                        value="{{ old('email', $request->email) }}"
                        placeholder="contato@imobiliaria.com.br"
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
                <a href="{{ route('empresa.login') }}" class="client-outline-link">Voltar para o login</a>
            </form>
        </div>
    </section>
</div>
@endsection
