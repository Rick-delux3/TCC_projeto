@extends('layout-inicial.dashboard_User')

@section('content_w')
@php
    /*
    |--------------------------------------------------------------------------
    | Variáveis principais do dashboard
    |--------------------------------------------------------------------------
    */

    $statusLabels = [
        'novo' => 'Novo',
        'em-andamento' => 'Em andamento',
        'qualificado' => 'Qualificado',
        'convertido' => 'Convertido',
        'perdido' => 'Perdido',
    ];

    $syncStatus = $syncStatus ?? 'idle';
    $syncError = $syncError ?? null;

    $totalLeads = $dashboardStats['totalLeads'] ?? 0;
    $newLeads = $dashboardStats['newLeads'] ?? 0;
    $recentLeads = $dashboardStats['recentLeads'] ?? 0;
    $withPhone = $dashboardStats['withPhone'] ?? 0;
    $withoutPhone = $dashboardStats['withoutPhone'] ?? 0;
    $latestLeadAt = $dashboardStats['latestLeadAt'] ?? null;
    $filteredLeads = $dashboardStats['filteredLeads'] ?? $leads->total();

    $topTags = $topTags ?? collect();
    $filterTags = $filterTags ?? collect();
    $selectedTag = $selectedTag ?? '';
    $isTagFiltered = filled($selectedTag);
    $companyTagName = mb_strtolower(trim((string) ($company->name ?? '')));

    $currentStart = $leads->firstItem() ?? 0;
    $currentEnd = $leads->lastItem() ?? 0;

    /*
    |--------------------------------------------------------------------------
    | Nova lógica de acesso ao formulário
    |--------------------------------------------------------------------------
    | leadFormUrl deve apontar para:
    | route('simulation.registered-company.access')
    |
    | leadAccessCode é a chave curta da imobiliária.
    */

    $leadFormUrl = $leadFormUrl ?? null;
    $leadAccessCode = $leadAccessCode ?? null;
    $leadFormAvailable = filled($leadFormUrl);
    $leadAccessCodeAvailable = filled($leadAccessCode);

    $hasSyncFailed = $syncStatus === 'failed';
    $isSyncBusy = in_array($syncStatus, ['queued', 'running'], true);
    $shouldAutoShowSyncToast = in_array($syncStatus, ['queued', 'running', 'failed'], true);

    $syncBadgeClass = match ($syncStatus) {
        'queued' => 'text-bg-warning',
        'running' => 'text-bg-primary',
        'done' => 'text-bg-success',
        'failed' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };

    $syncLabel = match ($syncStatus) {
        'queued' => 'Na fila',
        'running' => 'Sincronizando',
        'done' => 'Atualizado',
        'failed' => 'Falhou',
        default => 'Aguardando',
    };
@endphp

<style>
    .dashboard-shell {
        --dash-bg: #f8f9fb;
        --dash-surface: #ffffff;
        --dash-surface-soft: #f1f3f5;
        --dash-text: #212529;
        --dash-muted: #6c757d;
        --dash-border: rgba(0, 0, 0, .08);
        --dash-card-shadow: 0 .5rem 1.5rem rgba(0, 0, 0, .06);
        --dash-input-bg: #ffffff;
        --dash-toast-bg: rgba(255, 255, 255, .96);
        --dash-progress-bg: rgba(15, 23, 42, .08);
        background-color: var(--dash-bg);
        background:
            radial-gradient(circle at top left, rgba(13, 110, 253, .08), transparent 28rem),
            linear-gradient(180deg, #f8f9fb 0%, #ffffff 100%);
        color: var(--dash-text);
        min-height: 100vh;
        transition: background-color .2s ease, color .2s ease, border-color .2s ease;
    }

    .dashboard-shell[data-dashboard-theme="dark"] {
        --dash-bg: #0f172a;
        --dash-surface: #111827;
        --dash-surface-soft: #1f2937;
        --dash-text: #f8fafc;
        --dash-muted: #cbd5e1;
        --dash-border: rgba(255, 255, 255, .12);
        --dash-card-shadow: 0 .5rem 1.5rem rgba(0, 0, 0, .35);
        --dash-input-bg: #0f172a;
        --dash-toast-bg: rgba(17, 24, 39, .96);
        --dash-progress-bg: rgba(255, 255, 255, .08);
        background-color: var(--dash-bg);
        background:
            radial-gradient(circle at top left, rgba(59, 130, 246, .16), transparent 28rem),
            radial-gradient(circle at top right, rgba(37, 99, 235, .12), transparent 24rem),
            linear-gradient(180deg, #0f172a 0%, #020617 100%);
    }

    .dashboard-shell .card-body,
    .dashboard-shell .btn,
    .dashboard-shell .form-control,
    .dashboard-shell .form-select,
    .dashboard-shell .toast,
    .dashboard-shell .list-group-item,
    .dashboard-shell .page-link,
    .dashboard-shell .progress,
    .dashboard-shell .alert,
    .dashboard-shell .badge,
    .dashboard-shell hr,
    .dashboard-shell .border {
        transition: background-color .2s ease, color .2s ease, border-color .2s ease, box-shadow .2s ease;
    }

    .dashboard-shell .card:not(.dashboard-hero-card) {
        background-color: var(--dash-surface);
        color: var(--dash-text);
        border-color: var(--dash-border) !important;
        box-shadow: var(--dash-card-shadow) !important;
    }

    .dashboard-shell .text-muted {
        color: var(--dash-muted) !important;
    }

    .dashboard-shell .bg-light,
    .dashboard-shell .bg-body-tertiary {
        background-color: var(--dash-surface-soft) !important;
        color: var(--dash-text) !important;
    }

    .dashboard-shell .border,
    .dashboard-shell hr {
        border-color: var(--dash-border) !important;
    }

    .dashboard-shell .form-control,
    .dashboard-shell .form-select,
    .dashboard-shell .input-group-text {
        background-color: var(--dash-input-bg);
        color: var(--dash-text);
        border-color: var(--dash-border);
    }

    .dashboard-shell .form-control::placeholder {
        color: var(--dash-muted);
    }

    .dashboard-shell .form-control:focus,
    .dashboard-shell .form-select:focus {
        background-color: var(--dash-surface);
        color: var(--dash-text);
        border-color: rgba(13, 110, 253, .45);
        box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .16);
    }

    .dashboard-shell .list-group {
        --bs-list-group-bg: transparent;
        --bs-list-group-color: var(--dash-text);
        --bs-list-group-border-color: var(--dash-border);
        --bs-list-group-action-hover-bg: var(--dash-surface-soft);
        --bs-list-group-action-active-bg: var(--dash-surface-soft);
    }

    .dashboard-shell .list-group-item:not(.active) {
        color: var(--dash-text);
    }

    .dashboard-shell .pagination {
        --bs-pagination-color: var(--dash-text);
        --bs-pagination-bg: var(--dash-surface);
        --bs-pagination-border-color: var(--dash-border);
        --bs-pagination-hover-color: var(--dash-text);
        --bs-pagination-hover-bg: var(--dash-surface-soft);
        --bs-pagination-hover-border-color: var(--dash-border);
        --bs-pagination-focus-color: var(--dash-text);
        --bs-pagination-focus-bg: var(--dash-surface-soft);
        --bs-pagination-focus-box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .15);
        --bs-pagination-disabled-color: var(--dash-muted);
        --bs-pagination-disabled-bg: var(--dash-surface-soft);
        --bs-pagination-disabled-border-color: var(--dash-border);
        --bs-pagination-active-bg: #0d6efd;
        --bs-pagination-active-border-color: #0d6efd;
    }

    .dashboard-shell .toast {
        --bs-toast-bg: var(--dash-toast-bg);
        --bs-toast-color: var(--dash-text);
        --bs-toast-border-color: var(--dash-border);
        --bs-toast-header-bg: var(--dash-surface);
        --bs-toast-header-color: var(--dash-text);
        background-color: var(--dash-toast-bg);
        color: var(--dash-text);
        border-color: var(--dash-border) !important;
        box-shadow: var(--dash-card-shadow) !important;
    }

    .dashboard-shell .toast-header {
        background-color: var(--dash-surface);
        color: var(--dash-text);
        border-bottom: 1px solid var(--dash-border);
    }

    .dashboard-shell .progress {
        background-color: var(--dash-progress-bg);
    }

    .dashboard-shell .dashboard-filter-chip:not(.text-bg-primary),
    .dashboard-shell .dashboard-tag-chip:not(.text-bg-primary):not(.text-bg-secondary) {
        background-color: var(--dash-surface-soft) !important;
        color: var(--dash-text) !important;
        border-color: var(--dash-border) !important;
    }

    .dashboard-shell[data-dashboard-theme="dark"] .text-bg-light {
        background-color: var(--dash-surface-soft) !important;
        color: var(--dash-text) !important;
        border-color: var(--dash-border) !important;
    }

    .dashboard-shell[data-dashboard-theme="dark"] .text-dark {
        color: var(--dash-text) !important;
    }

    .dashboard-shell[data-dashboard-theme="dark"] .btn-outline-secondary,
    .dashboard-shell[data-dashboard-theme="dark"] .btn-outline-dark {
        color: #e5edf8;
        border-color: rgba(255, 255, 255, .22);
    }

    .dashboard-shell[data-dashboard-theme="dark"] .btn-outline-secondary:hover,
    .dashboard-shell[data-dashboard-theme="dark"] .btn-outline-dark:hover {
        background-color: var(--dash-surface-soft);
        color: #ffffff;
        border-color: rgba(255, 255, 255, .28);
    }

    .dashboard-shell[data-dashboard-theme="dark"] .btn-outline-primary {
        color: #8ec5ff;
        border-color: rgba(96, 165, 250, .52);
    }

    .dashboard-shell[data-dashboard-theme="dark"] .btn-outline-primary:hover {
        color: #ffffff;
        background-color: rgba(13, 110, 253, .18);
        border-color: rgba(96, 165, 250, .72);
    }

    .dashboard-shell[data-dashboard-theme="dark"] .alert-info {
        background-color: rgba(13, 202, 240, .14);
        color: #e6faff;
    }

    .dashboard-shell[data-dashboard-theme="dark"] .alert-danger {
        background-color: rgba(220, 53, 69, .16);
        color: #ffe8eb;
    }

    .dashboard-shell[data-dashboard-theme="dark"] .btn-close {
        filter: invert(1) grayscale(100%) brightness(220%);
    }

    .dashboard-hero-card {
        background: linear-gradient(135deg, #0d6efd 0%, #123b8f 100%);
        overflow: hidden;
        position: relative;
    }

    .dashboard-hero-card::after {
        content: "";
        position: absolute;
        width: 260px;
        height: 260px;
        right: -90px;
        top: -90px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .12);
    }

    .dashboard-hero-card::before {
        content: "";
        position: absolute;
        width: 180px;
        height: 180px;
        right: 80px;
        bottom: -100px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .08);
    }

    .dashboard-stat-card {
        transition: transform .2s ease, box-shadow .2s ease;
    }

    .dashboard-stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 .75rem 2rem rgba(0, 0, 0, .08) !important;
    }

    .lead-card {
        transition: transform .2s ease, box-shadow .2s ease;
    }

    .lead-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 .75rem 1.75rem rgba(0, 0, 0, .08) !important;
    }

    .lead-avatar {
        width: 46px;
        height: 46px;
    }

    .access-code-box {
        letter-spacing: .18rem;
    }

    .sync-toast {
        z-index: 1080;
    }

    @media (max-width: 768px) {
        .dashboard-hero-title {
            font-size: 2rem;
        }

        .access-code-box {
            letter-spacing: .1rem;
        }
    }
</style>

<div id="dashboardThemeRoot" class="dashboard-shell" data-dashboard-theme="light">
    <div class="container-fluid px-3 px-lg-4 py-4">

        {{-- Toast não bloqueante de sincronização --}}
        <div class="toast-container position-fixed top-0 end-0 p-3 sync-toast">
            <div
                id="syncStatusToast"
                class="toast border-0 shadow-lg rounded-4"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                data-bs-autohide="false"
            >
                <div class="toast-header border-0">
                    <span class="badge {{ $syncBadgeClass }} me-2" id="sync-toast-badge">
                        {{ $syncLabel }}
                    </span>

                    <strong class="me-auto" id="sync-toast-title">
                        Status da sincronização
                    </strong>

                    <small class="text-muted" id="sync-toast-percent">
                        0%
                    </small>

                    <button type="button" class="btn-close ms-2" data-bs-dismiss="toast" aria-label="Fechar"></button>
                </div>

                <div class="toast-body pt-0">
                    <p class="text-muted small mb-2" id="sync-toast-description">
                        Acompanhando a sincronização com a LeadLovers.
                    </p>

                    <div class="progress mb-2" style="height: 8px;">
                        <div
                            id="sync-toast-progress-bar"
                            class="progress-bar progress-bar-striped progress-bar-animated"
                            style="width: 0%;"
                            role="progressbar"
                            aria-valuenow="0"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        ></div>
                    </div>

                    <div class="small text-muted mb-3" id="sync-toast-summary">
                        Aguardando atualização.
                    </div>

                    <form method="POST" action="{{ route('Dashboard.syncAgain') }}" id="sync-toast-retry-form" class="d-none">
                        @csrf
                    </form>

                    <button type="button" class="btn btn-sm btn-danger d-none" id="sync-toast-retry-button">
                        Tentar sincronizar novamente
                    </button>
                </div>
            </div>
        </div>

        {{-- Cabeçalho moderno --}}
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
            <div>
                <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle mb-2">
                    Dashboard da imobiliária
                </span>

                <h1 class="h2 fw-bold mb-1">
                    Central de leads e simulações
                </h1>

                <p class="text-muted mb-0">
                    Acompanhe os leads vinculados à imobiliária, copie sua chave de acesso e consulte a sincronização com a LeadLovers.
                </p>
            </div>

            <div class="d-flex flex-column flex-sm-row gap-2">
                <form method="POST" action="{{ route('Dashboard.syncAgain') }}">
                    @csrf

                    <button
                        type="submit"
                        class="btn {{ $hasSyncFailed ? 'btn-danger' : 'btn-primary' }}"
                        @disabled($isSyncBusy)
                    >
                        @if ($isSyncBusy)
                            Sincronização em andamento
                        @elseif ($hasSyncFailed)
                            Tentar sincronização novamente
                        @else
                            Sincronizar leads
                        @endif
                    </button>
                </form>

                <a
                    href="{{ $leadFormUrl ?? '#' }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="btn btn-outline-primary {{ $leadFormAvailable ? '' : 'disabled' }}"
                    @if (!$leadFormAvailable) aria-disabled="true" tabindex="-1" @endif
                >
                    Abrir simulação
                </a>

                <button type="button" class="btn btn-outline-secondary" id="dashboardThemeToggle">
                    Modo escuro
                </button>
            </div>
        </div>

        {{-- Alertas de sincronização --}}
        @if ($isSyncBusy)
            <div class="alert alert-info border-0 shadow-sm rounded-4 mb-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <strong>Sincronização em andamento.</strong>
                        <div class="small">
                            Os leads estão sendo processados em segundo plano. Você pode continuar usando o painel normalmente.
                        </div>
                    </div>

                    <span class="badge {{ $syncBadgeClass }}">
                        {{ $syncLabel }}
                    </span>
                </div>
            </div>
        @endif

        @if ($hasSyncFailed)
            <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                    <div>
                        <strong>A sincronização falhou.</strong>
                        <div class="small">
                            {{ $syncError ?? 'Não foi possível concluir a sincronização. Tente novamente.' }}
                        </div>
                    </div>

                    <form method="POST" action="{{ route('Dashboard.syncAgain') }}">
                        @csrf
                        <button class="btn btn-sm btn-danger">
                            Tentar novamente
                        </button>
                    </form>
                </div>
            </div>
        @endif

        {{-- Bloco principal superior --}}
        <div class="row g-4 mb-4">

            {{-- Hero principal --}}
            <div class="col-12 col-xl-7">
                <div class="card border-0 shadow-sm rounded-5 dashboard-hero-card h-100 text-white">
                    <div class="card-body p-4 p-lg-5 position-relative">
                        <div class="row g-4 align-items-end">
                            <div class="col-12 col-lg-8">
                                <span class="badge bg-white text-primary mb-3">
                                    CRM operacional
                                </span>

                                <h2 class="display-6 fw-bold dashboard-hero-title mb-3">
                                    Leads organizados para atendimento rápido.
                                </h2>

                                <p class="text-white-50 mb-4">
                                    Use este painel para acompanhar entradas, filtrar origens e manter o processo comercial mais simples.
                                </p>

                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $totalLeads }} leads totais
                                    </span>

                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $recentLeads }} recentes
                                    </span>

                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $withPhone }} com telefone
                                    </span>
                                </div>
                            </div>

                            <div class="col-12 col-lg-4">
                                <div class="bg-white bg-opacity-10 rounded-4 p-3 border border-white border-opacity-25">
                                    <div class="small text-white-50 mb-1">
                                        Última entrada
                                    </div>

                                    <div class="fw-bold">
                                        {{ $latestLeadAt ? $latestLeadAt->format('d/m/Y H:i') : 'Sem leads sincronizados' }}
                                    </div>

                                    <hr class="border-white border-opacity-25">

                                    <div class="small text-white-50 mb-1">
                                        Status integração
                                    </div>

                                    <span class="badge {{ $syncBadgeClass }}">
                                        {{ $syncLabel }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            {{-- Acesso rápido --}}
            <div class="col-12 col-xl-5">
                <div class="card border-0 shadow-sm rounded-5 h-100">
                    <div class="card-body p-4 p-lg-5">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <span class="badge text-bg-dark mb-2">
                                    Acesso rápido
                                </span>

                                <h2 class="h4 fw-bold mb-1">
                                    Chave da imobiliária
                                </h2>

                                <p class="text-muted small mb-0">
                                    Compartilhe a chave junto com o link da página de simulação.
                                </p>
                            </div>

                            <span class="badge {{ $leadAccessCodeAvailable ? 'text-bg-success' : 'text-bg-danger' }}">
                                {{ $leadAccessCodeAvailable ? 'Ativa' : 'Indisponível' }}
                            </span>
                        </div>

                        <label class="form-label small text-muted">
                            Chave de acesso
                        </label>

                        <div class="input-group input-group-lg mb-3">
                            <input
                                type="text"
                                class="form-control fw-bold text-uppercase access-code-box"
                                value="{{ $leadAccessCode ?? '' }}"
                                readonly
                                id="dashboardLeadAccessCode"
                                @disabled(!$leadAccessCodeAvailable)
                            >

                            <button
                                class="btn btn-primary"
                                type="button"
                                id="dashboardLeadAccessCodeCopyButton"
                                @disabled(!$leadAccessCodeAvailable)
                            >
                                Copiar
                            </button>
                        </div>

                        <label class="form-label small text-muted">
                            Página de simulação
                        </label>

                        <div class="input-group mb-3">
                            <input
                                type="text"
                                class="form-control"
                                value="{{ $leadFormUrl ?? '' }}"
                                readonly
                                id="dashboardLeadFormLink"
                                @disabled(!$leadFormAvailable)
                            >

                            <button
                                class="btn btn-outline-primary"
                                type="button"
                                id="dashboardLeadFormCopyButton"
                                @disabled(!$leadFormAvailable)
                            >
                                Copiar link
                            </button>
                        </div>

                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <a
                                href="{{ $leadFormUrl ?? '#' }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-outline-dark {{ $leadFormAvailable ? '' : 'disabled' }}"
                                id="dashboardLeadFormOpenButton"
                                @if (!$leadFormAvailable) aria-disabled="true" tabindex="-1" @endif
                            >
                                Abrir página
                            </a>

                            <span id="dashboardLeadFormCopyStatus" class="small {{ $leadFormAvailable ? 'text-muted' : 'text-danger' }}">
                                {{ $leadFormAvailable ? 'Envie o link e a chave para quem for preencher.' : 'Página indisponível.' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

        </div>


        {{-- Métricas --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-primary-subtle text-primary">
                                Base
                            </span>
                            <span class="text-primary fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalLeads }}
                        </div>

                        <div class="text-muted small">
                            leads disponíveis
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-success-subtle text-success">
                                Novos
                            </span>
                            <span class="text-success fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $newLeads }}
                        </div>

                        <div class="text-muted small">
                            em fase inicial
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-info-subtle text-info">
                                Contato
                            </span>
                            <span class="text-info fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $withPhone }}
                        </div>

                        <div class="text-muted small">
                            com telefone
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-warning-subtle text-warning">
                                Recentes
                            </span>
                            <span class="text-warning fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $recentLeads }}
                        </div>

                        <div class="text-muted small">
                            últimos 7 dias
                        </div>
                    </div>
                </div>
            </div>
        </div>


        {{-- Conteúdo principal --}}
        <div class="row g-4">

            {{-- Coluna de leads --}}
            <div class="col-12 col-xxl-8">

                {{-- Filtros --}}
                <div class="card border-0 shadow-sm rounded-5 mb-4" id="leads-section">
                    <div class="card-body p-4">
                        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3">
                            <div>
                                <span class="badge text-bg-secondary mb-2">
                                    Filtros
                                </span>

                                <h2 class="h4 fw-bold mb-1">
                                    Fila comercial
                                </h2>

                                <p class="text-muted mb-0">
                                    {{ $isTagFiltered ? $filteredLeads . ' leads encontrados no filtro atual.' : $totalLeads . ' leads cadastrados na base.' }}
                                </p>
                            </div>

                            <form method="GET" action="{{ url()->current() }}#leads-section" class="row g-2 align-items-end">
                                <div class="col-12 col-md-auto">
                                    <label for="crm-tag-filter" class="form-label small text-muted">
                                        Filtrar por tag
                                    </label>

                                    <select id="crm-tag-filter" name="tag" class="form-select">
                                        <option value="">Todas as tags</option>

                                        @foreach ($filterTags as $tag => $count)
                                            <option value="{{ $tag }}" @selected($selectedTag === $tag)>
                                                {{ $tag }} ({{ $count }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-12 col-md-auto d-flex gap-2">
                                    <button class="btn btn-primary" type="submit">
                                        Aplicar
                                    </button>

                                    @if ($isTagFiltered)
                                        <a href="{{ url()->current() }}#leads-section" class="btn btn-outline-secondary">
                                            Limpar
                                        </a>
                                    @endif
                                </div>
                            </form>
                        </div>

                        @if ($filterTags->isNotEmpty())
                            <div class="d-flex flex-wrap gap-2 mt-4">
                                @foreach ($filterTags->take(10) as $tag => $count)
                                    <a
                                        href="{{ request()->fullUrlWithQuery(['tag' => $tag, 'page' => 1]) }}#leads-section"
                                        class="badge rounded-pill text-decoration-none px-3 py-2 dashboard-filter-chip {{ $selectedTag === $tag ? 'text-bg-primary' : 'text-bg-light border text-dark' }}"
                                    >
                                        {{ $tag }} · {{ $count }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>


                {{-- Lista de leads --}}
                @if ($leads->total() > 0)
                    <div class="row g-3">
                        @foreach ($leads as $lead)
                            @php
                                $leadName = $lead->nome ?: 'Lead sem nome';
                                $leadEmail = $lead->email ?: 'E-mail não informado';
                                $leadPhone = $lead->tel ?: 'Telefone não informado';
                                $leadCity = $lead->cidade_imovel ?? $lead->cidade ?? 'Cidade não informada';

                                $leadDate = $lead->created_at ? $lead->created_at->format('d/m/Y') : 'Sem data';
                                $leadTime = $lead->created_at ? $lead->created_at->format('H:i') : '--:--';

                                $statusKey = \Illuminate\Support\Str::slug($lead->status ?: 'novo');
                                $statusLabel = $statusLabels[$statusKey] ?? ucfirst(str_replace('-', ' ', $statusKey));

                                $allTags = collect(preg_split('/\s*,\s*/', $lead->tags_originais ?? ''))
                                    ->filter(fn ($tag) => filled($tag))
                                    ->map(fn ($tag) => trim($tag))
                                    ->reject(function ($tag) use ($companyTagName) {
                                        return mb_strtolower(trim($tag)) === $companyTagName;
                                    });

                                $visibleTags = $allTags->take(3);
                                $remainingTags = max($allTags->count() - $visibleTags->count(), 0);

                                $leadInitials = collect(preg_split('/\s+/', trim($leadName)))
                                    ->filter()
                                    ->take(2)
                                    ->map(fn ($part) => strtoupper(substr($part, 0, 1)))
                                    ->implode('');

                                $statusBadge = match ($statusKey) {
                                    'novo' => 'text-bg-primary',
                                    'em-andamento' => 'text-bg-warning',
                                    'qualificado' => 'text-bg-info',
                                    'convertido' => 'text-bg-success',
                                    'perdido' => 'text-bg-danger',
                                    default => 'text-bg-secondary',
                                };
                            @endphp

                            <div class="col-12 col-lg-6">
                                <article class="card border-0 shadow-sm rounded-5 lead-card h-100">
                                    <div class="card-body p-4">
                                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                            <div class="d-flex align-items-center gap-3">
                                                <div class="lead-avatar rounded-4 bg-primary text-white d-flex align-items-center justify-content-center fw-bold">
                                                    {{ $leadInitials ?: 'L' }}
                                                </div>

                                                <div>
                                                    <h3 class="h6 fw-bold mb-1">
                                                        {{ $leadName }}
                                                    </h3>

                                                    <div class="small text-muted">
                                                        {{ $leadCity }}
                                                    </div>
                                                </div>
                                            </div>

                                            <span class="badge {{ $statusBadge }}">
                                                {{ $statusLabel }}
                                            </span>
                                        </div>

                                        <div class="border rounded-4 p-3 mb-3 bg-light">
                                            <div class="row g-2">
                                                <div class="col-12">
                                                    <div class="small text-muted">E-mail</div>

                                                    @if ($lead->email)
                                                        <a href="mailto:{{ $lead->email }}" class="fw-semibold text-decoration-none">
                                                            {{ $leadEmail }}
                                                        </a>
                                                    @else
                                                        <span class="fw-semibold">{{ $leadEmail }}</span>
                                                    @endif
                                                </div>

                                                <div class="col-12 col-sm-6">
                                                    <div class="small text-muted">Telefone</div>
                                                    <div class="fw-semibold">{{ $leadPhone }}</div>
                                                </div>

                                                <div class="col-12 col-sm-6">
                                                    <div class="small text-muted">Entrada</div>
                                                    <div class="fw-semibold">{{ $leadDate }} às {{ $leadTime }}</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-flex flex-wrap gap-1 mb-3">
                                            @forelse ($visibleTags as $tag)
                                                <a
                                                    href="{{ request()->fullUrlWithQuery(['tag' => $tag, 'page' => 1]) }}#leads-section"
                                                    class="badge rounded-pill text-decoration-none dashboard-tag-chip {{ $selectedTag === $tag ? 'text-bg-primary' : 'text-bg-light border text-dark' }}"
                                                >
                                                    {{ $tag }}
                                                </a>
                                            @empty
                                                <span class="badge rounded-pill text-bg-light border text-muted dashboard-tag-chip">
                                                    Sem tag
                                                </span>
                                            @endforelse

                                            @if ($remainingTags > 0)
                                                <span class="badge rounded-pill text-bg-secondary">
                                                    +{{ $remainingTags }}
                                                </span>
                                            @endif
                                        </div>

                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary flex-fill">
                                                Visualizar
                                            </button>

                                            <button type="button" class="btn btn-sm btn-primary flex-fill">
                                                Analisar
                                            </button>
                                        </div>
                                    </div>
                                </article>
                            </div>
                        @endforeach
                    </div>

                    <div class="card border-0 shadow-sm rounded-4 mt-4">
                        <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <p class="text-muted small mb-0">
                                Exibindo {{ $currentStart }} a {{ $currentEnd }} de {{ $filteredLeads }} leads{{ $isTagFiltered ? ' filtrados' : '' }}.
                            </p>

                            @if ($leads->hasPages())
                                <div>
                                    {{ $leads->onEachSide(1)->links('pagination::bootstrap-5') }}
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="card border-0 shadow-sm rounded-5">
                        <div class="card-body text-center p-5">
                            <span class="badge text-bg-light border mb-3">
                                Base vazia
                            </span>

                            @if ($isTagFiltered)
                                <h3 class="h5 fw-bold">
                                    Nenhum lead encontrado com a tag {{ $selectedTag }}.
                                </h3>

                                <p class="text-muted">
                                    Tente escolher outra tag ou limpe o filtro para visualizar toda a base.
                                </p>

                                <a href="{{ url()->current() }}#leads-section" class="btn btn-outline-secondary">
                                    Limpar filtro
                                </a>
                            @else
                                <h3 class="h5 fw-bold">
                                    Nenhum lead encontrado.
                                </h3>

                                <p class="text-muted">
                                    Assim que novos contatos forem captados ou sincronizados, eles aparecerão aqui.
                                </p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>


            {{-- Coluna lateral --}}
            <div class="col-12 col-xxl-4">

                {{-- Resumo operacional --}}
                <div class="card border-0 shadow-sm rounded-5 mb-4">
                    <div class="card-body p-4">
                        <span class="badge text-bg-dark mb-2">
                            Operação
                        </span>

                        <h2 class="h5 fw-bold mb-3">
                            Resumo da base
                        </h2>

                        <div class="vstack gap-3">
                            <div class="d-flex justify-content-between align-items-center border-bottom pb-3">
                                <div>
                                    <div class="fw-semibold">Com telefone</div>
                                    <div class="small text-muted">Prontos para contato direto</div>
                                </div>
                                <span class="badge text-bg-success rounded-pill">{{ $withPhone }}</span>
                            </div>

                            <div class="d-flex justify-content-between align-items-center border-bottom pb-3">
                                <div>
                                    <div class="fw-semibold">Sem telefone</div>
                                    <div class="small text-muted">Precisam de complemento</div>
                                </div>
                                <span class="badge text-bg-warning rounded-pill">{{ $withoutPhone }}</span>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold">Últimos 7 dias</div>
                                    <div class="small text-muted">Entradas recentes</div>
                                </div>
                                <span class="badge text-bg-primary rounded-pill">{{ $recentLeads }}</span>
                            </div>
                        </div>
                    </div>
                </div>


                {{-- Tags principais --}}
                <div class="card border-0 shadow-sm rounded-5 mb-4">
                    <div class="card-body p-4">
                        <span class="badge text-bg-secondary mb-2">
                            Segmentação
                        </span>

                        <h2 class="h5 fw-bold mb-3">
                            Tags com maior volume
                        </h2>

                        <div class="list-group list-group-flush">
                            @forelse ($topTags as $tag => $count)
                                <a
                                    href="{{ request()->fullUrlWithQuery(['tag' => $tag, 'page' => 1]) }}#leads-section"
                                    class="list-group-item list-group-item-action d-flex justify-content-between align-items-center px-0 {{ $selectedTag === $tag ? 'active px-3 rounded-3' : '' }}"
                                >
                                    <div>
                                        <div class="fw-semibold">{{ $tag }}</div>
                                        <div class="small {{ $selectedTag === $tag ? 'text-white-50' : 'text-muted' }}">
                                            Leads desta origem
                                        </div>
                                    </div>

                                    <span class="badge {{ $selectedTag === $tag ? 'text-bg-light text-primary' : 'text-bg-primary' }} rounded-pill">
                                        {{ $count }}
                                    </span>
                                </a>
                            @empty
                                <p class="text-muted small mb-0">
                                    As tags aparecerão aqui assim que existirem leads segmentados.
                                </p>
                            @endforelse
                        </div>
                    </div>
                </div>


                {{-- Ajuda rápida --}}
                <div class="card border-0 shadow-sm rounded-5">
                    <div class="card-body p-4">
                        <span class="badge text-bg-info mb-2">
                            Guia rápido
                        </span>

                        <h2 class="h5 fw-bold mb-3">
                            Como usar este painel
                        </h2>

                        <div class="vstack gap-3">
                            <div class="d-flex gap-3">
                                <span class="badge rounded-circle text-bg-primary d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                                    1
                                </span>
                                <div>
                                    <div class="fw-semibold">Compartilhe a chave</div>
                                    <div class="small text-muted">Envie a chave e o link da simulação para sua equipe.</div>
                                </div>
                            </div>

                            <div class="d-flex gap-3">
                                <span class="badge rounded-circle text-bg-primary d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                                    2
                                </span>
                                <div>
                                    <div class="fw-semibold">Acompanhe novos leads</div>
                                    <div class="small text-muted">Os leads captados aparecerão nesta fila comercial.</div>
                                </div>
                            </div>

                            <div class="d-flex gap-3">
                                <span class="badge rounded-circle text-bg-primary d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                                    3
                                </span>
                                <div>
                                    <div class="fw-semibold">Filtre por tag</div>
                                    <div class="small text-muted">Use as tags para localizar grupos de leads rapidamente.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const statusUrl = "{{ route('Dashboard.syncStatus') }}";
    const currentStatus = @json($syncStatus);
    const initialSyncError = @json($syncError);
    const initialTotalLeads = @json($totalLeads);
    const leadFormUrl = @json($leadFormUrl);
    const leadAccessCode = @json($leadAccessCode);
    const shouldAutoShowSyncToast = @json($shouldAutoShowSyncToast);
    const dashboardThemeRoot = document.getElementById('dashboardThemeRoot');
    const dashboardThemeToggle = document.getElementById('dashboardThemeToggle');
    const dashboardThemeStorageKey = 'dashboard-theme';

    const toastElement = document.getElementById('syncStatusToast');
    const syncToast = toastElement ? new bootstrap.Toast(toastElement, { autohide: false }) : null;

    const toastBadgeEl = document.getElementById('sync-toast-badge');
    const toastTitleEl = document.getElementById('sync-toast-title');
    const toastDescriptionEl = document.getElementById('sync-toast-description');
    const toastProgressEl = document.getElementById('sync-toast-progress-bar');
    const toastPercentEl = document.getElementById('sync-toast-percent');
    const toastSummaryEl = document.getElementById('sync-toast-summary');
    const toastRetryButtonEl = document.getElementById('sync-toast-retry-button');
    const toastRetryFormEl = document.getElementById('sync-toast-retry-form');

    const dashboardLeadAccessCodeCopyButton = document.getElementById('dashboardLeadAccessCodeCopyButton');
    const dashboardLeadAccessCodeInput = document.getElementById('dashboardLeadAccessCode');

    const dashboardLeadFormCopyButton = document.getElementById('dashboardLeadFormCopyButton');
    const dashboardLeadFormInput = document.getElementById('dashboardLeadFormLink');
    const dashboardLeadFormCopyStatus = document.getElementById('dashboardLeadFormCopyStatus');
    const dashboardLeadFormOpenButton = document.getElementById('dashboardLeadFormOpenButton');

    let intervalId = null;
    let doneReloadTimeout = null;

    function applyDashboardTheme(theme) {
        if (!dashboardThemeRoot || !dashboardThemeToggle) {
            return;
        }

        const normalizedTheme = theme === 'dark' ? 'dark' : 'light';

        dashboardThemeRoot.setAttribute('data-dashboard-theme', normalizedTheme);
        dashboardThemeToggle.textContent = normalizedTheme === 'dark' ? 'Modo claro' : 'Modo escuro';
        dashboardThemeToggle.classList.toggle('btn-outline-light', normalizedTheme === 'dark');
        dashboardThemeToggle.classList.toggle('btn-outline-secondary', normalizedTheme !== 'dark');
    }

    if (dashboardThemeRoot && dashboardThemeToggle) {
        let savedTheme = 'light';

        try {
            savedTheme = localStorage.getItem(dashboardThemeStorageKey) || 'light';
        } catch (error) {
            savedTheme = 'light';
        }

        applyDashboardTheme(savedTheme);

        dashboardThemeToggle.addEventListener('click', function () {
            const currentTheme = dashboardThemeRoot.getAttribute('data-dashboard-theme') || 'light';
            const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';

            try {
                localStorage.setItem(dashboardThemeStorageKey, nextTheme);
            } catch (error) {
                console.warn('Nao foi possivel salvar o tema no navegador.', error);
            }

            applyDashboardTheme(nextTheme);
        });
    }

    function stopPolling() {
        if (intervalId) {
            clearInterval(intervalId);
            intervalId = null;
        }
    }

    function progressForStatus(status, totalLeads) {
        if (status === 'queued') {
            return 18;
        }

        if (status === 'running') {
            return Math.min(84, 46 + Math.min(Number(totalLeads || 0), 38));
        }

        return 100;
    }

    function getToastCopy(status, payload) {
        const leadsCount = Number(payload.totalLeads || 0);
        const progress = progressForStatus(status, leadsCount);

        if (status === 'queued') {
            return {
                variant: 'warning',
                badge: 'Na fila',
                title: 'Preparando sincronização',
                description: 'A importação foi colocada na fila e será processada em instantes.',
                progress: progress,
                summary: 'Aguardando início do processamento.',
                retry: false
            };
        }

        if (status === 'running') {
            return {
                variant: 'primary',
                badge: 'Sincronizando',
                title: 'Sincronização em andamento',
                description: 'Os leads estão sendo sincronizados em segundo plano.',
                progress: progress,
                summary: leadsCount > 0 ? `${leadsCount} leads disponíveis até agora.` : 'Lendo registros da integração.',
                retry: false
            };
        }

        if (status === 'failed') {
            return {
                variant: 'danger',
                badge: 'Falhou',
                title: 'Falha na sincronização',
                description: payload.syncError || 'Não foi possível concluir a sincronização.',
                progress: 100,
                summary: 'Revise a integração ou tente novamente.',
                retry: true
            };
        }

        return {
            variant: 'success',
            badge: 'Atualizado',
            title: 'Sincronização concluída',
            description: 'A base local foi atualizada.',
            progress: 100,
            summary: `${leadsCount} leads disponíveis no painel.`,
            retry: false
        };
    }

    function renderToast(copy) {
        if (!toastElement) {
            return;
        }

        toastBadgeEl.className = `badge text-bg-${copy.variant} me-2`;
        toastBadgeEl.textContent = copy.badge;

        toastTitleEl.textContent = copy.title;
        toastDescriptionEl.textContent = copy.description;
        toastPercentEl.textContent = `${copy.progress}%`;
        toastSummaryEl.textContent = copy.summary;

        toastProgressEl.style.width = `${copy.progress}%`;
        toastProgressEl.setAttribute('aria-valuenow', copy.progress);
        toastProgressEl.className = `progress-bar progress-bar-striped progress-bar-animated bg-${copy.variant}`;

        if (copy.retry) {
            toastRetryButtonEl.classList.remove('d-none');
        } else {
            toastRetryButtonEl.classList.add('d-none');
        }
    }

    function setCopyStatus(target, message, type = 'muted') {
        if (!target) {
            return;
        }

        target.textContent = message;
        target.className = `small text-${type}`;
    }

    function bindCopyButton(copyButton, input, statusEl, valueToCopy, successMessage, defaultMessage) {
        if (!copyButton || !input) {
            return;
        }

        copyButton.addEventListener('click', async function () {
            const value = valueToCopy || input.value;

            if (!value) {
                if (statusEl) {
                    setCopyStatus(statusEl, 'Informação indisponível para cópia.', 'danger');
                }

                return;
            }

            try {
                await navigator.clipboard.writeText(value);

                if (statusEl) {
                    setCopyStatus(statusEl, successMessage, 'success');

                    setTimeout(function () {
                        setCopyStatus(statusEl, defaultMessage, 'muted');
                    }, 2600);
                } else {
                    const originalText = copyButton.textContent;
                    copyButton.textContent = 'Copiado';

                    setTimeout(function () {
                        copyButton.textContent = originalText;
                    }, 2000);
                }
            } catch (error) {
                input.focus();
                input.select();

                if (statusEl) {
                    setCopyStatus(statusEl, 'Não foi possível copiar automaticamente. Use Ctrl+C.', 'danger');
                }
            }
        });
    }

    function bindOpenButton(openButton, url) {
        if (!openButton) {
            return;
        }

        openButton.addEventListener('click', function (event) {
            if (!url) {
                event.preventDefault();
            }
        });
    }

    if (toastRetryButtonEl) {
        toastRetryButtonEl.addEventListener('click', function () {
            if (toastRetryFormEl) {
                toastRetryFormEl.submit();
            }
        });
    }

    bindOpenButton(dashboardLeadFormOpenButton, leadFormUrl);

    bindCopyButton(
        dashboardLeadAccessCodeCopyButton,
        dashboardLeadAccessCodeInput,
        null,
        leadAccessCode,
        'Chave copiada com sucesso.',
        ''
    );

    bindCopyButton(
        dashboardLeadFormCopyButton,
        dashboardLeadFormInput,
        dashboardLeadFormCopyStatus,
        leadFormUrl,
        'Link copiado com sucesso.',
        'Envie o link e a chave para quem for preencher.'
    );

    async function checkSyncStatus() {
        try {
            const response = await fetch(statusUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                stopPolling();
                return;
            }

            const data = await response.json();

            if (!data.authenticated) {
                stopPolling();
                return;
            }

            if (data.sync_status === 'queued' || data.sync_status === 'running') {
                renderToast(getToastCopy(data.sync_status, {
                    totalLeads: data.total_leads,
                    syncError: data.sync_error
                }));

                if (syncToast) {
                    syncToast.show();
                }
            }

            if (data.sync_status === 'done') {
                stopPolling();

                renderToast(getToastCopy('done', {
                    totalLeads: data.total_leads,
                    syncError: data.sync_error
                }));

                if (syncToast) {
                    syncToast.show();
                }

                if (doneReloadTimeout) {
                    clearTimeout(doneReloadTimeout);
                }

                doneReloadTimeout = setTimeout(function () {
                    window.location.reload();
                }, 900);
            }

            if (data.sync_status === 'failed') {
                stopPolling();

                renderToast(getToastCopy('failed', {
                    totalLeads: data.total_leads,
                    syncError: data.sync_error
                }));

                if (syncToast) {
                    syncToast.show();
                }
            }
        } catch (error) {
            console.error('Erro ao consultar status da sincronização:', error);
        }
    }

    window.addEventListener('beforeunload', function () {
        stopPolling();

        if (doneReloadTimeout) {
            clearTimeout(doneReloadTimeout);
        }
    });

    if (currentStatus === 'queued' || currentStatus === 'running') {
        renderToast(getToastCopy(currentStatus, {
            totalLeads: initialTotalLeads,
            syncError: initialSyncError
        }));

        if (syncToast && shouldAutoShowSyncToast) {
            syncToast.show();
        }

        intervalId = setInterval(checkSyncStatus, 5000);
        checkSyncStatus();
    }

    if (currentStatus === 'failed') {
        renderToast(getToastCopy('failed', {
            totalLeads: initialTotalLeads,
            syncError: initialSyncError
        }));

        if (syncToast && shouldAutoShowSyncToast) {
            syncToast.show();
        }
    }
});


filterLinks.forEach(function (link) {
        link.addEventListener('click', saveScrollPosition);
    });

    const savedPosition = sessionStorage.getItem(scrollKey);

    if (savedPosition !== null) {
        window.scrollTo({
            top: parseInt(savedPosition, 10),
            behavior: 'instant'
        });

        sessionStorage.removeItem(scrollKey);
    }
</script>
@endsection
