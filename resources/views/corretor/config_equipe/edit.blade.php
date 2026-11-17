@extends('layout-inicial.Dashboard_Admin')

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

<div class="dashboard-shell team-create-page team-motion-page management-workspace">
    <div class="container-fluid px-0">
        <div class="team-create-backdrop">
            <x-management-page-header
                title="Editar integrante" eyebrow="Gestão da equipe"
                description="Revise os dados, a disponibilidade do acesso e as permissões do integrante."
                :back-url="$indexRoute" back-label="Voltar para a equipe"
            />
            <div class="card border-0 shadow-sm rounded-5 team-create-modal">
                <div class="card-body p-4 p-lg-5">
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

                        <div class="management-section-heading">
                            <span aria-hidden="true">01</span>
                            <div><h2>Dados do integrante</h2><p>Identificação e credenciais para acessar a plataforma.</p></div>
                        </div>
                        <div class="row g-3 team-primary-fields">
                            <div class="col-12 col-md-6">
                                <label for="nome" class="form-label">
                                    Nome do integrante
                                </label>

                                <div class="input-group">
                                    <span class="input-group-text bg-white">
                                        <i class="bi bi-person" aria-hidden="true"></i>
                                    </span>

                                    <input
                                        type="text"
                                        id="nome"
                                        name="nome"
                                        value="{{ old('nome', $integrante?->name ?? $integrante?->nome) }}"
                                        class="form-control @error('nome') is-invalid @enderror"
                                        @error('nome') aria-invalid="true" aria-describedby="team-nome-error" @enderror
                                        placeholder="Nome completo"
                                        autocomplete="name"
                                        required
                                    >
                                </div>

                                @error('nome')
                                    <div id="team-nome-error" class="invalid-feedback d-block">
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
                                        <i class="bi bi-envelope" aria-hidden="true"></i>
                                    </span>

                                    <input
                                        type="email"
                                        id="email"
                                        name="email"
                                        value="{{ old('email', $integrante?->email) }}"
                                        class="form-control @error('email') is-invalid @enderror"
                                        @error('email') aria-invalid="true" aria-describedby="team-email-error" @enderror
                                        placeholder="integrante@email.com"
                                        autocomplete="email"
                                        required
                                    >
                                </div>

                                @error('email')
                                    <div id="team-email-error" class="invalid-feedback d-block">
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
                                        <i class="bi bi-key" aria-hidden="true"></i>
                                    </span>

                                    <input
                                        type="password"
                                        id="member-password"
                                        name="password"
                                        class="form-control @error('password') is-invalid @enderror"
                                        @error('password') aria-invalid="true" aria-describedby="team-password-error" @enderror
                                        placeholder="Deixe em branco para manter"
                                        autocomplete="new-password"
                                    >
                                </div>

                                @error('password')
                                    <div id="team-password-error" class="invalid-feedback d-block">
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
                                        <i class="bi bi-shield-check" aria-hidden="true"></i>
                                    </span>

                                    <input
                                        type="password"
                                        id="member-password-confirmation"
                                        name="password_confirmation"
                                        class="form-control @error('password_confirmation') is-invalid @enderror"
                                        @error('password_confirmation') aria-invalid="true" aria-describedby="team-password_confirmation-error" @enderror
                                        placeholder="Repita apenas se alterar"
                                        autocomplete="new-password"
                                    >
                                </div>

                                @error('password_confirmation')
                                    <div id="team-password_confirmation-error" class="invalid-feedback d-block">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>
                        </div>

                        <div class="team-status-panel p-3 p-md-4 mt-4" data-team-panel>
                            <div class="d-flex align-items-start gap-3">
                                <span class="team-permission-icon">
                                    <i class="bi bi-envelope-check" aria-hidden="true"></i>
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
                                        <i class="bi bi-check-circle" aria-hidden="true"></i>
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
                                    <i class="bi bi-shield-lock" aria-hidden="true"></i>
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
                                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
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
