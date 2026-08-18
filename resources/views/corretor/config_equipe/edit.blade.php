@extends('layout-inicial.dashboard_Admin')

@push('styles')
    @vite('resources/css/config-equipe.css')
@endpush

@push('scripts')
    @vite('resources/js/config-equipe.js')
@endpush

@section('content_a')
@once
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
@endonce

@php
    $integrante = $integrante ?? null;

    $indexRoute = route('admin.config-equipe.index');
    $updateRoute = route('admin.config-equipe.update', $integrante);

    $currentPermissions = $integrante?->permissions ?? [];
    $permissionsWereSubmitted = old('permissions_submitted') === '1';
    $oldPermissions = old(
        'permissions',
        $permissionsWereSubmitted ? [] : $currentPermissions,
    );
    $oldPermissions = is_array($oldPermissions) ? $oldPermissions : [];

    $isActive = old(
        'active',
        (bool) ($integrante?->active ?? true) ? '1' : '0'
    ) === '1';

    $conviteAceito = filled($integrante?->invite_accepted_at);

    $conviteExpirado = ! $conviteAceito
        && filled($integrante?->invite_expires_at)
        && $integrante->invite_expires_at->isPast();

    $convitePendente = ! $conviteAceito
        && filled($integrante?->invite_expires_at)
        && ! $integrante->invite_expires_at->isPast();

    $conviteNaoEnviado = ! $conviteAceito
        && blank($integrante?->invite_last_sent_at);

@endphp

<style>
    .team-create-page {
        --nvs-navy: #030133;
        --nvs-blue: #146fb6;
        --nvs-pink: #fd1e6e;
        --nvs-soft: #eef4fb;
        background:
            linear-gradient(135deg, rgba(3, 1, 51, .82), rgba(20, 111, 182, .58)),
            linear-gradient(180deg, #f6f9fd 0%, #e8f1fa 100%);
        min-height: calc(100vh - 98px);
    }

    .team-create-page .btn-primary {
        --bs-btn-bg: var(--nvs-blue);
        --bs-btn-border-color: var(--nvs-blue);
        --bs-btn-hover-bg: #0f5f9f;
        --bs-btn-hover-border-color: #0f5f9f;
        --bs-btn-active-bg: #0c4f86;
        --bs-btn-active-border-color: #0c4f86;
        box-shadow: 0 14px 26px rgba(20, 111, 182, .22);
    }

    .team-create-page .btn-outline-secondary {
        --bs-btn-color: #4b5a70;
        --bs-btn-border-color: rgba(75, 90, 112, .28);
        --bs-btn-hover-bg: #f4f7fb;
        --bs-btn-hover-border-color: rgba(75, 90, 112, .4);
        --bs-btn-hover-color: var(--nvs-navy);
    }

    .team-create-backdrop {
        position: relative;
        min-height: calc(100vh - 98px);
        display: grid;
        place-items: center;
        padding: 48px 16px;
        overflow: hidden;
    }

    .team-create-backdrop::before {
        content: "";
        position: absolute;
        inset: 0;
        background-image:
            linear-gradient(rgba(255, 255, 255, .10) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255, 255, 255, .10) 1px, transparent 1px);
        background-size: 48px 48px;
        opacity: .34;
    }

    .team-create-backdrop::after {
        content: "";
        position: absolute;
        inset: 18px;
        border: 1px solid rgba(255, 255, 255, .14);
        border-radius: 34px;
        background: rgba(255, 255, 255, .08);
        backdrop-filter: blur(5px);
    }

    .team-create-modal {
        position: relative;
        z-index: 1;
        width: min(960px, 100%);
        overflow: hidden;
    }

    .team-create-modal::before {
        content: "";
        position: absolute;
        inset: 0 0 auto 0;
        height: 7px;
        background: linear-gradient(90deg, var(--nvs-navy), var(--nvs-blue), var(--nvs-pink));
    }

    .team-modal-close {
        position: absolute;
        top: 18px;
        right: 18px;
        z-index: 2;
        width: 42px;
        height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        color: var(--nvs-navy);
        border: 1px solid rgba(3, 1, 51, .08);
        background: #ffffff;
        box-shadow: 0 12px 24px rgba(3, 1, 51, .10);
    }

    .team-modal-close:hover {
        color: var(--nvs-pink);
        border-color: rgba(253, 30, 110, .22);
        background: #fff7fa;
    }

    .team-create-brand {
        width: 48px;
        height: 48px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 18px;
        background: #f7faff;
        border: 1px solid rgba(20, 111, 182, .12);
    }

    .team-create-brand img {
        width: 36px;
        height: 36px;
        object-fit: contain;
    }

    .team-create-icon {
        width: 78px;
        height: 78px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 26px;
        color: #ffffff;
        background: linear-gradient(135deg, var(--nvs-blue), var(--nvs-pink));
        box-shadow: 0 18px 36px rgba(20, 111, 182, .22);
        font-size: 2.25rem;
    }

    .team-create-form .form-label {
        color: #344057;
        font-weight: 700;
    }

    .team-create-form .form-control,
    .team-create-form .input-group-text {
        border-color: rgba(3, 1, 51, .12);
    }

    .team-create-form .input-group-text {
        color: var(--nvs-blue);
    }

    .team-create-form .form-control:focus,
    .team-create-form .form-check-input:focus {
        border-color: rgba(20, 111, 182, .48);
        box-shadow: 0 0 0 .25rem rgba(20, 111, 182, .14);
    }

    .team-status-panel,
    .team-permissions-panel {
        border: 1px solid rgba(3, 1, 51, .10);
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        border-radius: 24px;
    }

    .team-active-switch .form-check-input {
        width: 3.25rem;
        height: 1.65rem;
        cursor: pointer;
    }

    .team-active-switch .form-check-input:checked {
        background-color: var(--nvs-blue);
        border-color: var(--nvs-blue);
    }

    .team-permission-option {
        min-height: 72px;
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 14px;
        border: 1px solid rgba(3, 1, 51, .10);
        border-radius: 18px;
        background: #ffffff;
        transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }

    .team-permission-option:hover {
        transform: translateY(-1px);
        border-color: rgba(20, 111, 182, .34);
        box-shadow: 0 12px 24px rgba(3, 1, 51, .06);
    }

    .team-permission-option .form-check-input {
        margin: 0;
        flex: 0 0 auto;
    }

    .team-permission-option .form-check-input:checked {
        background-color: var(--nvs-blue);
        border-color: var(--nvs-blue);
    }

    .team-permission-icon {
        width: 42px;
        height: 42px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        border-radius: 15px;
        color: var(--nvs-blue);
        background: rgba(20, 111, 182, .10);
    }

    .team-actions {
        border-top: 1px solid rgba(3, 1, 51, .08);
    }

    @media (max-width: 767.98px) {
        .team-create-backdrop {
            min-height: calc(100vh - 82px);
            padding: 24px 10px;
            place-items: start center;
        }

        .team-create-backdrop::after {
            inset: 10px;
            border-radius: 24px;
        }

        .team-create-icon {
            width: 64px;
            height: 64px;
            border-radius: 22px;
            font-size: 1.85rem;
        }

        .team-modal-close {
            top: 14px;
            right: 14px;
            width: 38px;
            height: 38px;
        }
    }
</style>

<div class="dashboard-shell team-create-page team-motion-page">
    <div class="container-fluid px-0">
        <div class="team-create-backdrop">
            <div class="card border-0 shadow-sm rounded-5 team-create-modal" data-team-reveal>
                <a href="{{ $indexRoute }}" class="btn team-modal-close" aria-label="Voltar para a listagem da equipe">
                    <i class="bi bi-x-lg"></i>
                </a>

                <div class="card-body p-4 p-lg-5">
                    <div class="team-form-brand-row d-flex align-items-center gap-3 mb-4 pe-5">
                        <span class="team-create-brand">
                            <x-brand-logo />
                        </span>

                        <div>
                            <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle">
                                Equipe {{ config('branding.profiles.'.config('branding.active', 'tcc').'.name', 'NVS Seguros') }}
                            </span>
                        </div>
                    </div>

                    <div class="team-form-heading text-center mb-4">
                        <span class="team-create-icon mb-3">
                            <i class="bi bi-person-gear"></i>
                        </span>

                        <h1 class="h3 fw-bold mb-2">
                            Editar integrante
                        </h1>

                        <p class="text-muted mb-0">
                            Atualize os dados, o status e as permissões operacionais do integrante.
                        </p>
                    </div>

                    @if ($errors->any())
                        <div class="alert alert-danger rounded-4 border-0 mb-4">
                            <div class="fw-semibold">
                                Revise os campos destacados antes de salvar as alterações.
                            </div>
                        </div>
                    @endif

                    <form method="POST" action="{{ $updateRoute }}" class="team-create-form" data-team-form novalidate>
                        @csrf
                        @method('PUT')

                        <div class="row g-3 team-primary-fields">
                            <div class="col-12 col-md-6">
                                <label for="nome" class="form-label">
                                    Nome do integrante
                                </label>

                                <div class="input-group">
                                    <span class="input-group-text bg-white">
                                        <i class="bi bi-person"></i>
                                    </span>

                                    <input
                                        type="text"
                                        id="nome"
                                        name="nome"
                                        value="{{ old('nome', $integrante?->name ?? $integrante?->nome) }}"
                                        class="form-control @error('nome') is-invalid @enderror"
                                        placeholder="Nome completo"
                                        autocomplete="name"
                                        required
                                    >
                                </div>

                                @error('nome')
                                    <div class="invalid-feedback d-block">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="email" class="form-label">
                                    E-mail
                                </label>

                                <div class="input-group">
                                    <span class="input-group-text bg-white">
                                        <i class="bi bi-envelope"></i>
                                    </span>

                                    <input
                                        type="email"
                                        id="email"
                                        name="email"
                                        value="{{ old('email', $integrante?->email) }}"
                                        class="form-control @error('email') is-invalid @enderror"
                                        placeholder="integrante@email.com"
                                        autocomplete="email"
                                        required
                                    >
                                </div>

                                @error('email')
                                    <div class="invalid-feedback d-block">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="member-password" class="form-label">
                                    Nova senha
                                </label>

                                <div class="input-group">
                                    <span class="input-group-text bg-white">
                                        <i class="bi bi-key"></i>
                                    </span>

                                    <input
                                        type="password"
                                        id="member-password"
                                        name="password"
                                        class="form-control @error('password') is-invalid @enderror"
                                        placeholder="Deixe em branco para manter"
                                        autocomplete="new-password"
                                    >
                                </div>

                                @error('password')
                                    <div class="invalid-feedback d-block">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <div class="col-12 col-md-6">
                                <label for="member-password-confirmation" class="form-label">
                                    Confirmar nova senha
                                </label>

                                <div class="input-group">
                                    <span class="input-group-text bg-white">
                                        <i class="bi bi-shield-check"></i>
                                    </span>

                                    <input
                                        type="password"
                                        id="member-password-confirmation"
                                        name="password_confirmation"
                                        class="form-control @error('password_confirmation') is-invalid @enderror"
                                        placeholder="Repita apenas se alterar"
                                        autocomplete="new-password"
                                    >
                                </div>

                                @error('password_confirmation')
                                    <div class="invalid-feedback d-block">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>

                        <div class="team-status-panel p-3 p-md-4 mt-4" data-team-panel>
                            <div class="d-flex align-items-start gap-3">
                                <span class="team-permission-icon">
                                    <i class="bi bi-envelope-check"></i>
                                </span>

                                <div class="flex-grow-1">
                                    <h2 class="h6 fw-bold mb-2">Situação do convite</h2>

                                    @if ($conviteAceito)
                                        <span class="badge text-bg-success">Aceito</span>
                                        @if (filled($integrante?->invite_accepted_at))
                                            <div class="small text-muted mt-2">
                                                Aceito em {{ $integrante->invite_accepted_at->format('d/m/Y H:i') }}
                                            </div>
                                        @endif
                                    @elseif ($conviteExpirado)
                                        <span class="badge text-bg-danger">Expirado</span>
                                        <div class="small text-muted mt-2">
                                            Expirou em {{ $integrante->invite_expires_at->format('d/m/Y H:i') }}
                                        </div>
                                    @elseif ($convitePendente)
                                        <span class="badge text-bg-warning text-dark">Pendente</span>
                                        <div class="small text-muted mt-2">
                                            Expira em {{ $integrante->invite_expires_at->format('d/m/Y H:i') }}
                                        </div>
                                    @else
                                        <span class="badge text-bg-secondary">Não enviado</span>
                                    @endif

                                    @if (filled($integrante?->invite_last_sent_at))
                                        <div class="small text-muted mt-2">
                                            Último envio: {{ $integrante->invite_last_sent_at->format('d/m/Y H:i') }}
                                        </div>
                                    @endif

                                    <div class="small text-muted mt-1">
                                        Quantidade de envios: {{ (int) ($integrante?->invite_send_count ?? 0) }}
                                    </div>

                                    <div class="small text-muted mt-2">
                                        O reenvio de convite deve ser feito pela listagem da equipe.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="team-status-panel p-3 p-md-4 mt-4" data-team-panel>
                            <input type="hidden" name="active" value="0">

                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                                <div class="d-flex align-items-start gap-3">
                                    <span class="team-permission-icon">
                                        <i class="bi bi-check-circle"></i>
                                    </span>

                                    <div>
                                        <label for="active" class="fw-bold mb-1">
                                            Status ativo
                                        </label>

                                        <div class="text-muted small">
                                            Controle se o integrante pode acessar a plataforma.
                                            Integrantes inativos não podem entrar no sistema nem receber novos convites.
                                        </div>
                                    </div>
                                </div>

                                <div class="form-check form-switch team-active-switch mb-0">
                                    <input
                                        class="form-check-input @error('active') is-invalid @enderror"
                                        type="checkbox"
                                        role="switch"
                                        id="active"
                                        name="active"
                                        value="1"
                                        @checked($isActive)
                                    >

                                    <label class="form-check-label fw-semibold ms-2 {{ $isActive ? 'is-active' : 'is-inactive' }}" for="active" data-team-status-label>
                                        {{ $isActive ? 'Ativo' : 'Inativo' }}
                                    </label>
                                </div>
                            </div>

                            @error('active')
                                <div class="invalid-feedback d-block">
                                    {{ $message }}
                                </div>
                            @enderror
                        </div>

                        <div class="team-permissions-panel p-3 p-md-4 mt-4" data-team-panel>
                            <div class="d-flex align-items-start gap-3 mb-3">
                                <span class="team-permission-icon">
                                    <i class="bi bi-shield-lock"></i>
                                </span>

                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <h2 class="h6 fw-bold mb-0">
                                            Permissões operacionais
                                        </h2>
                                        <span
                                            class="team-permission-count"
                                            data-team-permission-count
                                            aria-live="polite"
                                            aria-atomic="true"
                                            hidden
                                        ></span>
                                    </div>

                                    <p class="text-muted small mb-0">
                                        Permissões já concedidas aparecem marcadas e podem ser atualizadas.
                                    </p>
                                </div>
                            </div>

                            @include('corretor.config_equipe.permission-groups', [
                                'selectedPermissions' => $oldPermissions,
                            ])

                            @if ($errors->has('permissions') || $errors->has('permissions.*'))
                                <div class="invalid-feedback d-block mt-2">
                                    {{ $errors->first('permissions') ?: $errors->first('permissions.*') }}
                                </div>
                            @endif
                        </div>

                        <div class="team-actions d-flex flex-column flex-sm-row-reverse gap-2 pt-4 mt-4">
                            <button type="submit" class="btn btn-primary px-4" data-team-submit>
                                <i class="bi bi-check2-circle me-1"></i>
                                Salvar alterações
                            </button>

                            <a href="{{ $indexRoute }}" class="btn btn-outline-secondary px-4">
                                Cancelar
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
