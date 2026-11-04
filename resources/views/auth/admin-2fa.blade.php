@extends('layout-inicial.app')

@section('body-class', config('branding.active', 'tcc') === 'tcc' ? 'tcc-company-auth-body' : '')
@section('main-class', config('branding.active', 'tcc') === 'tcc' ? 'tcc-company-auth-main tcc-company-auth-main--standalone' : '')
@section('hide-header', config('branding.active', 'tcc') === 'tcc' ? 'true' : 'false')

@section('content')
@if (config('branding.active', 'tcc') === 'tcc')
<div class="client-auth-page tcc-company-auth tcc-admin-verification">
    <section class="client-auth-shell" aria-labelledby="verification-title">
        <aside class="client-auth-aside tcc-auth-logo-aside">
            <img src="{{ asset('imgs/Logo_NVS.png') }}" class="tcc-auth-background-logo" alt="" aria-hidden="true">
            <div class="client-auth-overlay">
                <span class="client-badge">Acesso administrativo</span>
                <div class="grid gap-4">
                    <h2>Uma etapa a mais de proteção.</h2>
                    <p>Confirme seu primeiro acesso para continuar ao painel de administração com segurança.</p>
                </div>
                <p class="tcc-company-auth-aside-note">&copy; {{ now()->year }} NVS Seguros. Ambiente seguro e monitorado.</p>
            </div>
        </aside>

        <div class="client-auth-card">
            <header class="client-auth-header">
                <span class="client-auth-kicker">Verificação de segurança</span>
                <h1 id="verification-title">Confirme seu acesso</h1>
                <p>Digite o código de 6 dígitos enviado para seu e-mail.</p>
            </header>

            @foreach (['success' => 'success', 'info' => 'info'] as $status => $style)
                @if (session($status))
                    <div class="alert alert-{{ $style }}" role="status">{{ session($status) }}</div>
                @endif
            @endforeach
            @if ($errors->any())
                <div class="alert alert-danger" role="alert" id="verification-errors">
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('admin.2fa.verify') }}" class="client-auth-form grid gap-6">
                @csrf
                <div class="client-field">
                    <label for="code" class="client-label">Código de verificação</label>
                    <input type="text" name="code" id="code" class="client-input tcc-verification-code"
                        minlength="6" maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                        autocomplete="one-time-code" placeholder="000000" required autofocus
                        aria-describedby="verification-hint{{ $errors->any() ? ' verification-errors' : '' }}"
                        @error('code') aria-invalid="true" @enderror>
                    <p id="verification-hint" class="text-sm leading-relaxed text-slate-500">O código expira em 10 minutos.</p>
                </div>
                <button type="submit" class="client-submit">Confirmar código</button>
            </form>

            <div class="mt-5 grid gap-3">
                <form method="POST" action="{{ route('admin.2fa.resend') }}">
                    @csrf
                    <button type="submit" class="client-outline-link w-full">Reenviar código</button>
                </form>
                <form method="POST" action="{{ route('admin.logout') }}" class="text-center">
                    @csrf
                    <button type="submit" class="tcc-verification-exit">Sair da conta</button>
                </form>
            </div>
        </div>
    </section>
</div>
@else
<div class="container min-vh-100 d-flex align-items-center justify-content-center">
    <div class="card shadow-sm border-0" style="max-width: 460px; width: 100%;">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <h4 class="fw-bold mb-1">Verificação de primeiro acesso</h4>
                <p class="text-muted mb-0">
                    Digite o código de 6 dígitos enviado para seu e-mail.
                </p>
            </div>

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

            <form method="POST" action="{{ route('admin.2fa.verify') }}">
                @csrf

                <div class="mb-3">
                    <label for="code" class="form-label">Código de verificação</label>
                    <input
                        type="text"
                        name="code"
                        id="code"
                        class="form-control form-control-lg text-center"
                        maxlength="6"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        placeholder="000000"
                        required
                        autofocus
                    >
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    Confirmar código
                </button>
            </form>

            <form method="POST" action="{{ route('admin.2fa.resend') }}" class="mt-3 text-center">
                @csrf

                <button type="submit" class="btn btn-link text-decoration-none">
                    Reenviar código
                </button>
            </form>

            <form method="POST" action="{{ route('admin.logout') }}" class="mt-2 text-center">
                @csrf

                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    Sair
                </button>
            </form>

            <div class="mt-4 small text-muted text-center">
                Por segurança, o código expira em 10 minutos.
            </div>
        </div>
    </div>
</div>
@endif
@endsection
