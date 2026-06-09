@extends('layout-inicial.dashboard_User')

@section('content_w')
@php
    $user = auth()->user();

    $company = $user?->company ?? null;

    $companyName = $company?->name ?? $user?->name ?? 'Imobiliária';
    $companyEmail = $company?->email ?? $user?->email ?? 'E-mail não informado';

    $companyInitials = collect(preg_split('/\s+/', trim($companyName)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('');
@endphp

<div id="dashboardThemeRoot" class="dashboard-shell" data-dashboard-theme="light">
    <div class="container-fluid px-3 px-lg-4 py-4">

        {{-- Mensagens de sessão --}}
        @if (session('status') === 'profile-updated')
            <div class="alert alert-success rounded-4 border-0 shadow-sm">
                Perfil atualizado com sucesso.
            </div>
        @endif

        @if (session('status') === 'password-updated')
            <div class="alert alert-success rounded-4 border-0 shadow-sm">
                Senha atualizada com sucesso.
            </div>
        @endif

        {{-- Cabeçalho da página --}}
        <div class="card border-0 shadow-sm rounded-5 mb-4 overflow-hidden">
            <div class="card-body p-4 p-lg-5">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-4">
                    <div>
                        <span class="badge text-bg-primary mb-2">
                            Gerenciar conta
                        </span>

                        <h1 class="h3 fw-bold mb-2">
                            Perfil da imobiliária
                        </h1>

                        <p class="text-muted mb-0">
                            Atualize as informações de acesso, senha e dados básicos da conta.
                        </p>
                    </div>

                    {{-- Card resumido da imobiliária --}}
                    <div class="d-flex align-items-center gap-3 bg-light rounded-4 p-3 border">
                        <div class="profile-company-avatar">
                            {{ $companyInitials ?: 'IM' }}
                        </div>

                        <div>
                            <div class="fw-bold">
                                {{ $companyName }}
                            </div>

                            <div class="small text-muted">
                                {{ $companyEmail }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">

            {{-- Dados do perfil --}}
            <div class="col-12 col-xl-7">
                <div class="card border-0 shadow-sm rounded-5 h-100">
                    <div class="card-body p-4 p-lg-5">
                        <div class="mb-4">
                            <span class="badge text-bg-light border mb-2">
                                Dados de acesso
                            </span>

                            <h2 class="h5 fw-bold mb-1">
                                Informações do perfil
                            </h2>

                            <p class="text-muted small mb-0">
                                Esses dados são usados para identificar o usuário da imobiliária no sistema.
                            </p>
                        </div>

                        <form method="POST" action="{{ route('profile.update') }}">
                            @csrf
                            @method('PATCH')

                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="name" class="form-label">
                                        Nome
                                    </label>

                                    <input
                                        type="text"
                                        id="name"
                                        name="name"
                                        class="form-control form-control-lg @error('name') is-invalid @enderror"
                                        value="{{ old('name', $user->name) }}"
                                        required
                                        autofocus
                                    >

                                    @error('name')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>

                                <div class="col-12">
                                    <label for="email" class="form-label">
                                        E-mail
                                    </label>

                                    <input
                                        type="email"
                                        id="email"
                                        name="email"
                                        class="form-control form-control-lg @error('email') is-invalid @enderror"
                                        value="{{ old('email', $user->email) }}"
                                        required
                                    >

                                    @error('email')
                                        <div class="invalid-feedback">
                                            {{ $message }}
                                        </div>
                                    @enderror
                                </div>
                            </div>

                            <div class="d-flex justify-content-end mt-4">
                                <button type="submit" class="btn btn-primary px-4">
                                    Salvar alterações
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Alterar senha --}}
            <div class="col-12 col-xl-5">
                <div class="card border-0 shadow-sm rounded-5 h-100">
                    <div class="card-body p-4 p-lg-5">
                        <div class="mb-4">
                            <span class="badge text-bg-warning mb-2">
                                Segurança
                            </span>

                            <h2 class="h5 fw-bold mb-1">
                                Alterar senha
                            </h2>

                            <p class="text-muted small mb-0">
                                Use uma senha forte, com letras e números.
                            </p>
                        </div>

                        <form method="POST" action="{{ route('password.update') }}">
                            @csrf
                            @method('PUT')

                            <div class="mb-3">
                                <label for="current_password" class="form-label">
                                    Senha atual
                                </label>

                                <input
                                    type="password"
                                    id="current_password"
                                    name="current_password"
                                    class="form-control @error('current_password', 'updatePassword') is-invalid @enderror"
                                    autocomplete="current-password"
                                    required
                                >

                                @error('current_password', 'updatePassword')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    Nova senha
                                </label>

                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="form-control @error('password', 'updatePassword') is-invalid @enderror"
                                    autocomplete="new-password"
                                    required
                                >

                                @error('password', 'updatePassword')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                @enderror
                            </div>

                            <div class="mb-4">
                                <label for="password_confirmation" class="form-label">
                                    Confirmar nova senha
                                </label>

                                <input
                                    type="password"
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    class="form-control"
                                    autocomplete="new-password"
                                    required
                                >
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-warning px-4">
                                    Atualizar senha
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Zona de perigo --}}
            <div class="col-12">
                <div class="card border-0 shadow-sm rounded-5">
                    <div class="card-body p-4 p-lg-5">
                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                            <div>
                                <span class="badge text-bg-danger mb-2">
                                    Zona de perigo
                                </span>

                                <h2 class="h5 fw-bold mb-1">
                                    Excluir conta
                                </h2>

                                <p class="text-muted small mb-0">
                                    Esta ação remove o usuário de acesso. Use apenas se tiver certeza.
                                </p>
                            </div>

                            <button
                                type="button"
                                class="btn btn-outline-danger"
                                data-bs-toggle="modal"
                                data-bs-target="#deleteAccountModal"
                            >
                                Excluir minha conta
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

{{-- Modal de confirmação de exclusão --}}
<div
    class="modal fade"
    id="deleteAccountModal"
    tabindex="-1"
    aria-labelledby="deleteAccountModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-5 border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="deleteAccountModalLabel">
                        Confirmar exclusão da conta
                    </h5>

                    <p class="text-muted small mb-0">
                        Essa ação não pode ser desfeita.
                    </p>
                </div>

                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <form method="POST" action="{{ route('profile.destroy') }}">
                @csrf
                @method('DELETE')

                <div class="modal-body">
                    <p class="text-muted">
                        Para confirmar, informe sua senha atual.
                    </p>

                    <label for="delete_password" class="form-label">
                        Senha atual
                    </label>

                    <input
                        type="password"
                        id="delete_password"
                        name="password"
                        class="form-control @error('password', 'userDeletion') is-invalid @enderror"
                        required
                    >

                    @error('password', 'userDeletion')
                        <div class="invalid-feedback">
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        Cancelar
                    </button>

                    <button type="submit" class="btn btn-danger">
                        Excluir conta
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection