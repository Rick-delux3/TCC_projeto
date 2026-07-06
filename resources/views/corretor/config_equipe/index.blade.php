@extends('layout-inicial.dashboard_Admin')

@section('content_a')
@php
    use Illuminate\Support\Facades\Route;

    /*
    |--------------------------------------------------------------------------
    | Dados principais
    |--------------------------------------------------------------------------
    | Esta view espera receber uma coleção chamada $corretores.
    | Ela deve conter CEO + integrantes.
    */

    $corretores = $corretores ?? collect();

    $adminLogado = auth('admin')->user();

    $nomeOrganizacao = $adminLogado?->nome_empresa
        ?? $adminLogado?->empresa
        ?? 'NVS Seguros';

    $totalCorretores = $corretores->count();

    $totalIntegrantes = $corretores
        ->filter(fn ($corretor) => ($corretor->role ?? null) === 'integrante')
        ->count();

    $totalAtivos = $corretores
        ->filter(fn ($corretor) => (bool) ($corretor->active ?? true))
        ->count();

    $convitesPendentes = $corretores
        ->filter(function ($corretor) {
            return ($corretor->role ?? null) === 'integrante'
                && blank($corretor->invite_accepted_at ?? null);
        })
        ->count();

    $search = request('search', '');

    $createRoute = Route::has('admin.config-equipe.create')
        ? route('admin.config-equipe.create')
        : '#';

    $indexRoute = Route::has('admin.config-equipe.index')
        ? route('admin.config-equipe.index')
        : url()->current();

    $dashboardRoute = Route::has('Dashboard-Admin')
        ? route('Dashboard-Admin')
        : '#';

    $getInitials = function ($name) {
        return collect(preg_split('/\s+/', trim((string) $name)))
            ->filter()
            ->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    };

    $roleLabel = function ($role) {
        return match ($role) {
            'CEO' => 'CEO',
            'integrante' => 'Integrante',
            default => ucfirst((string) $role),
        };
    };

    $roleBadge = function ($role) {
        return match ($role) {
            'CEO' => 'text-bg-primary',
            'integrante' => 'text-bg-secondary',
            default => 'text-bg-light text-dark border',
        };
    };
@endphp

<div class="dashboard-shell">
    <div class="container-fluid px-3 px-lg-4 py-4">

        {{-- Alertas --}}
        @if (session('success'))
            <div class="alert alert-success rounded-4 border-0 shadow-sm mb-4">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-warning rounded-4 border-0 shadow-sm mb-4">
                {{ session('error') }}
            </div>
        @endif

        {{-- Cabeçalho da página --}}
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
            <div>
                <nav aria-label="breadcrumb" class="mb-2">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <a href="{{ $dashboardRoute }}" class="text-decoration-none">
                                Dashboard
                            </a>
                        </li>

                        <li class="breadcrumb-item active" aria-current="page">
                            Equipe
                        </li>
                    </ol>
                </nav>

                <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle mb-2">
                    Configurações de equipe
                </span>

                <h1 class="h2 fw-bold mb-1">
                    Gerenciar equipe
                </h1>

                <p class="text-muted mb-0">
                    Visualize o CEO e os integrantes da corretora. O CEO não pode ser editado nesta área.
                </p>
            </div>

            <div class="d-flex flex-column flex-sm-row gap-2">
                <a href="{{ $dashboardRoute }}" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>
                    Voltar ao dashboard
                </a>

                <a href="{{ $createRoute }}" class="btn btn-primary {{ $createRoute === '#' ? 'disabled' : '' }}">
                    <i class="bi bi-person-plus me-1"></i>
                    Convidar novo integrante
                </a>
            </div>
        </div>

        {{-- Resumo superior --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-primary-subtle text-primary">
                                Equipe
                            </span>

                            <i class="bi bi-people text-primary"></i>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalCorretores }}
                        </div>

                        <div class="text-muted small">
                            membros cadastrados
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-secondary">
                                Integrantes
                            </span>

                            <i class="bi bi-person-badge text-secondary"></i>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalIntegrantes }}
                        </div>

                        <div class="text-muted small">
                            integrantes da equipe
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-success-subtle text-success">
                                Ativos
                            </span>

                            <i class="bi bi-check-circle text-success"></i>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalAtivos }}
                        </div>

                        <div class="text-muted small">
                            acessos habilitados
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-warning-subtle text-warning">
                                Convites
                            </span>

                            <i class="bi bi-envelope text-warning"></i>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $convitesPendentes }}
                        </div>

                        <div class="text-muted small">
                            pendentes de aceite
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Bloco principal --}}
        <div class="card border-0 shadow-sm rounded-5 mb-4">
            <div class="card-body p-4">

                <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
                    <div>
                        <span class="badge text-bg-dark mb-2">
                            Integrantes
                        </span>

                        <h2 class="h4 fw-bold mb-1">
                            Lista da equipe
                        </h2>

                        <p class="text-muted mb-0">
                            Consulte os membros cadastrados e desabilite o acesso dos integrantes quando necessário.
                        </p>
                    </div>

                    <form method="GET" action="{{ $indexRoute }}" class="d-flex flex-column flex-sm-row gap-2">
                        <div class="input-group">
                            <span class="input-group-text bg-white">
                                <i class="bi bi-search"></i>
                            </span>

                            <input
                                type="text"
                                name="search"
                                value="{{ $search }}"
                                class="form-control"
                                placeholder="Buscar integrante"
                                autocomplete="off"
                            >
                        </div>

                        <button type="submit" class="btn btn-outline-primary">
                            Buscar
                        </button>

                        @if (filled($search))
                            <a href="{{ $indexRoute }}" class="btn btn-outline-secondary">
                                Limpar
                            </a>
                        @endif
                    </form>
                </div>

                {{-- Tabela --}}
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Membro</th>
                                <th>E-mail</th>
                                <th>Cargo</th>
                                <th>Convite</th>
                                <th>Desde</th>
                                <th>Status</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse ($corretores as $corretor)
                                @php
                                    $isCorretorCeo = ($corretor->role ?? null) === 'CEO';

                                    $corretorNome = $corretor->nome
                                        ?? $corretor->name
                                        ?? 'Nome não informado';

                                    $corretorEmail = $corretor->email
                                        ?? 'E-mail não informado';

                                    $corretorInitials = $getInitials($corretorNome);

                                    $editRoute = Route::has('admin.config-equipe.edit')
                                        ? route('admin.config-equipe.edit', $corretor)
                                        : '#';

                                    $updateRoute = Route::has('admin.config-equipe.update')
                                        ? route('admin.config-equipe.update', $corretor)
                                        : '#';

                                    $permissions = collect($corretor->permissions ?? []);

                                    $isActive = (bool) ($corretor->active ?? true);

                                    $conviteAceito = filled($corretor->invite_accepted_at ?? null);

                                    $dataEntrada = $corretor->created_at
                                        ? $corretor->created_at->format('d/m/Y')
                                        : 'Não informado';
                                @endphp

                                <tr>
                                    {{-- Membro --}}
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <span class="rounded-4 bg-primary-subtle text-primary d-flex align-items-center justify-content-center fw-bold" style="width: 42px; height: 42px;">
                                                {{ $corretorInitials ?: 'CO' }}
                                            </span>

                                            <div class="min-w-0">
                                                <div class="fw-semibold text-truncate">
                                                    {{ $corretorNome }}
                                                </div>

                                                <div class="small text-muted">
                                                    {{ $isCorretorCeo ? 'Dono da corretora' : 'Integrante da equipe' }}
                                                </div>
                                            </div>
                                        </div>
                                    </td>

                                    {{-- E-mail --}}
                                    <td>
                                        <a href="mailto:{{ $corretorEmail }}" class="text-decoration-none fw-semibold">
                                            {{ $corretorEmail }}
                                        </a>
                                    </td>

                                    {{-- Cargo --}}
                                    <td>
                                        <span class="badge {{ $roleBadge($corretor->role ?? null) }}">
                                            {{ $roleLabel($corretor->role ?? null) }}
                                        </span>
                                    </td>

                                    {{-- Convite --}}
                                    <td>
                                        @if ($isCorretorCeo)
                                            <span class="badge text-bg-light border text-muted">
                                                Não se aplica
                                            </span>
                                        @elseif ($conviteAceito)
                                            <span class="badge text-bg-success">
                                                Aceito
                                            </span>
                                        @else
                                            <span class="badge text-bg-warning text-dark">
                                                Pendente
                                            </span>
                                        @endif
                                    </td>

                                    {{-- Desde --}}
                                    <td>
                                        <span class="text-muted">
                                            {{ $dataEntrada }}
                                        </span>
                                    </td>

                                    {{-- Status --}}
                                    <td>
                                        @if ($isCorretorCeo)
                                            <span class="badge text-bg-primary">
                                                Sempre ativo
                                            </span>
                                        @else
                                            <form method="POST" action="{{ $updateRoute }}" class="d-inline">
                                                @csrf
                                                @method('PUT')

                                                {{-- Campos necessários para o update não apagar dados --}}
                                                <input type="hidden" name="nome" value="{{ $corretorNome }}">
                                                <input type="hidden" name="email" value="{{ $corretorEmail }}">
                                                <input type="hidden" name="active" value="0">

                                                @foreach ($permissions as $permission)
                                                    <input type="hidden" name="permissions[]" value="{{ $permission }}">
                                                @endforeach

                                                <div class="form-check form-switch mb-0">
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        role="switch"
                                                        name="active"
                                                        value="1"
                                                        id="activeSwitch{{ $corretor->id }}"
                                                        @checked($isActive)
                                                        onchange="this.form.submit()"
                                                        @disabled($updateRoute === '#')
                                                    >

                                                    <label class="form-check-label small" for="activeSwitch{{ $corretor->id }}">
                                                        {{ $isActive ? 'Ativo' : 'Desabilitado' }}
                                                    </label>
                                                </div>
                                            </form>
                                        @endif
                                    </td>

                                    {{-- Ações --}}
                                    <td class="text-end">
                                        @if ($isCorretorCeo)
                                            <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                                                CEO não editável
                                            </button>
                                        @else
                                            <a
                                                href="{{ $editRoute }}"
                                                class="btn btn-sm btn-outline-primary {{ $editRoute === '#' ? 'disabled' : '' }}"
                                            >
                                                <i class="bi bi-pencil-square me-1"></i>
                                                Editar
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <div class="mb-3">
                                            <i class="bi bi-people text-muted fs-1"></i>
                                        </div>

                                        <h3 class="h5 fw-bold">
                                            Nenhum membro encontrado.
                                        </h3>

                                        <p class="text-muted mb-3">
                                            Quando integrantes forem cadastrados, eles aparecerão aqui.
                                        </p>

                                        <a href="{{ $createRoute }}" class="btn btn-primary {{ $createRoute === '#' ? 'disabled' : '' }}">
                                            <i class="bi bi-person-plus me-1"></i>
                                            Convidar integrante
                                        </a>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Observações --}}
        <div class="card border-0 shadow-sm rounded-5">
            <div class="card-body p-4">
                <div class="d-flex gap-3 align-items-start">
                    <span class="badge rounded-circle text-bg-primary d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                        <i class="bi bi-info-lg"></i>
                    </span>

                    <div>
                        <h2 class="h6 fw-bold mb-1">
                            Regras da equipe
                        </h2>

                        <p class="text-muted small mb-0">
                            O CEO é o dono da corretora e não pode ser editado nesta tela.
                            Integrantes podem ser editados, receber permissões e ter o acesso desabilitado pelo switch de status.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection