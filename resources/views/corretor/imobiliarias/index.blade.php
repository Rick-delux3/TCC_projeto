@extends('layout-inicial.Dashboard_Admin')

@push('styles')
    @vite('resources/css/imobiliarias-admin.css')
@endpush

@push('scripts')
    @vite('resources/js/imobiliarias-admin.js')
@endpush

@section('content_a')
@php
    $search = (string) ($filters['search'] ?? '');
    $status = $filters['status'] ?? null;
    $hasFilters = filled($search) || in_array($status, ['active', 'inactive'], true);
    $editingCompanyId = (int) old('_editing_company_id', 0);
    $editingCompany = $editingCompanyId > 0
        ? $companies->firstWhere('id', $editingCompanyId)
        : null;
    $hasEditErrors = $editingCompanyId > 0 && $errors->any();

    $formatCnpj = static function (?string $value): string {
        $numbers = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (strlen($numbers) === 11) {
            return substr($numbers, 0, 3).'.'.substr($numbers, 3, 3).'.'.substr($numbers, 6, 3).'-'.substr($numbers, 9, 2);
        }

        if (strlen($numbers) !== 14) {
            return filled($value) ? (string) $value : 'Não informado';
        }

        return substr($numbers, 0, 2).'.'.substr($numbers, 2, 3).'.'.substr($numbers, 5, 3)
            .'/'.substr($numbers, 8, 4).'-'.substr($numbers, 12, 2);
    };

    $formatPhone = static function (?string $value): string {
        $numbers = preg_replace('/\D+/', '', (string) $value) ?? '';

        if (strlen($numbers) === 11) {
            return '('.substr($numbers, 0, 2).') '.substr($numbers, 2, 5).'-'.substr($numbers, 7, 4);
        }

        if (strlen($numbers) === 10) {
            return '('.substr($numbers, 0, 2).') '.substr($numbers, 2, 4).'-'.substr($numbers, 6, 4);
        }

        return filled($value) ? (string) $value : 'Não informado';
    };

    $location = static function ($company): string {
        $city = trim((string) ($company->city ?? ''));
        $state = mb_strtoupper(trim((string) ($company->state ?? '')));

        return collect([$city, $state])->filter()->implode(' / ') ?: 'Não informado';
    };
@endphp

<div class="dashboard-shell real-estate-admin real-estate-index-page">
    <div class="container-fluid company-index-container px-3 px-lg-4 py-4">
        @if (session('error'))
            <div class="alert alert-danger border-0 rounded-4 shadow-sm mb-4" role="alert" aria-live="assertive">
                <div class="d-flex gap-3 align-items-start">
                    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                    <div>{{ session('error') }}</div>
                </div>
            </div>
        @endif

        @if (session('info'))
            <div class="alert alert-info border-0 rounded-4 shadow-sm mb-4" role="status" aria-live="polite">
                <div class="d-flex gap-3 align-items-start">
                    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                    <div>{{ session('info') }}</div>
                </div>
            </div>
        @endif

        @if ($errors->any() && ! $hasEditErrors)
            <div class="alert alert-danger border-0 rounded-4 shadow-sm mb-4" role="alert">
                <div class="d-flex gap-3 align-items-start">
                    <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
                    <div>
                        <strong>Não foi possível aplicar os filtros.</strong>
                        <div>{{ $errors->first() }}</div>
                    </div>
                </div>
            </div>
        @endif

        <header class="company-index-header mb-4" aria-labelledby="companies-page-title" data-reveal>
            <div>
                <nav aria-label="Navegação estrutural" class="mb-3">
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <a href="{{ route('Dashboard-Admin') }}">Dashboard</a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">Imobiliárias</li>
                    </ol>
                </nav>

                <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
                    <div class="company-page-hero__copy">
                        <span class="company-eyebrow">
                            <i class="bi bi-buildings" aria-hidden="true"></i>
                            Gestão de parceiros
                        </span>
                        <h1 id="companies-page-title" class="display-6 fw-bold mb-2">Imobiliárias cadastradas</h1>
                        <p class="mb-0">
                            Consulte os dados de acesso, acompanhe a disponibilidade dos formulários e encontre
                            rapidamente uma imobiliária.
                        </p>
                    </div>

                    @can('create-real-estate-company')
                        <a href="{{ route('admin.imobiliarias.create') }}" class="btn btn-primary text-white company-index-create">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                            Cadastrar imobiliária
                        </a>
                    @endcan
                </div>
            </div>
        </header>

        <section class="company-overview-strip mb-4" aria-label="Resumo das imobiliárias" data-reveal data-reveal-delay="40">
            <div class="company-overview-item">
                <article class="company-summary-card company-summary-card--total h-100">
                    <div class="card-body">
                        <div class="company-summary-card__icon" aria-hidden="true">
                            <i class="bi bi-buildings"></i>
                        </div>
                        <div>
                            <p class="company-summary-card__label">Total de imobiliárias</p>
                            <p class="company-summary-card__value" data-count-up="{{ $summary['total'] ?? 0 }}">{{ number_format($summary['total'] ?? 0, 0, ',', '.') }}</p>
                            <p class="company-summary-card__hint mb-0">parceiros cadastrados</p>
                        </div>
                    </div>
                </article>
            </div>

            <div class="company-overview-item">
                <article class="company-summary-card company-summary-card--active h-100">
                    <div class="card-body">
                        <div class="company-summary-card__icon" aria-hidden="true">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div>
                            <p class="company-summary-card__label">Formulários ativos</p>
                            <p class="company-summary-card__value" data-count-up="{{ $summary['active'] ?? 0 }}">{{ number_format($summary['active'] ?? 0, 0, ',', '.') }}</p>
                            <p class="company-summary-card__hint mb-0">prontos para receber leads</p>
                        </div>
                    </div>
                </article>
            </div>

            <div class="company-overview-item">
                <article class="company-summary-card company-summary-card--inactive h-100">
                    <div class="card-body">
                        <div class="company-summary-card__icon" aria-hidden="true">
                            <i class="bi bi-pause-circle"></i>
                        </div>
                        <div>
                            <p class="company-summary-card__label">Formulários inativos</p>
                            <p class="company-summary-card__value" data-count-up="{{ $summary['inactive'] ?? 0 }}">{{ number_format($summary['inactive'] ?? 0, 0, ',', '.') }}</p>
                            <p class="company-summary-card__hint mb-0">temporariamente pausados</p>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <div class="company-directory" data-reveal data-reveal-delay="80">
        <section class="company-filter-card" aria-labelledby="company-filter-title">
            <div class="card-body p-3 p-lg-4">
                <div class="d-flex align-items-center justify-content-between gap-3 mb-3">
                    <div>
                        <h2 id="company-filter-title" class="h5 fw-bold mb-1">Encontre uma imobiliária</h2>
                        <p class="text-muted small mb-0">Use um ou mais filtros para refinar a listagem.</p>
                    </div>
                    @if ($hasFilters)
                        <span class="badge company-filter-badge">Filtros ativos</span>
                    @endif
                </div>

                <form method="GET" action="{{ route('admin.imobiliarias.index') }}" class="company-directory-filters">
                    <div class="company-directory-search">
                        <label for="company-search" class="form-label fw-semibold">Buscar</label>
                        <div class="input-group company-input-group">
                            <span class="input-group-text" aria-hidden="true"><i class="bi bi-search"></i></span>
                            <input
                                type="search"
                                id="company-search"
                                name="search"
                                value="{{ $search }}"
                                class="form-control"
                                maxlength="100"
                                placeholder="Nome, CPF/CNPJ ou e-mail"
                                autocomplete="off"
                            >
                        </div>
                    </div>

                    <div class="company-directory-status">
                        <label for="company-status" class="form-label fw-semibold">Status</label>
                        <select id="company-status" name="status" class="form-select">
                            <option value="">Todos</option>
                            <option value="active" @selected($status === 'active')>Ativos</option>
                            <option value="inactive" @selected($status === 'inactive')>Inativos</option>
                        </select>
                    </div>

                    <div class="company-directory-filter-actions">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill">
                                <i class="bi bi-funnel me-1" aria-hidden="true"></i>
                                Aplicar filtros
                            </button>
                            <a href="{{ route('admin.imobiliarias.index') }}" class="btn btn-outline-secondary" aria-label="Limpar todos os filtros">
                                Limpar
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        @if ($companies->isEmpty())
            <section class="company-empty-state" aria-live="polite">
                <div class="card-body text-center px-3 py-5">
                    <span class="company-empty-state__icon" aria-hidden="true">
                        <i class="bi {{ $hasFilters ? 'bi-search' : 'bi-buildings' }}"></i>
                    </span>

                    @if ($hasFilters)
                        <h2 class="h4 fw-bold mt-4">Nenhum resultado para os filtros aplicados</h2>
                        <p class="text-muted mx-auto mb-4">
                            Revise a busca ou o status selecionado para encontrar outras imobiliárias.
                        </p>
                        <a href="{{ route('admin.imobiliarias.index') }}" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                            Limpar filtros
                        </a>
                    @else
                        <h2 class="h4 fw-bold mt-4">Nenhuma imobiliária cadastrada</h2>
                        <p class="text-muted mx-auto mb-4">
                            Assim que uma imobiliária for cadastrada, seus dados aparecerão nesta página.
                        </p>
                        @can('create-real-estate-company')
                            <a href="{{ route('admin.imobiliarias.create') }}" class="btn btn-primary">
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
                                Cadastrar primeira imobiliária
                            </a>
                        @endcan
                    @endif
                </div>
            </section>
        @else
            <section class="company-list-card" aria-labelledby="company-list-title">
                <div class="card-body p-0">
                    <div class="company-list-card__header">
                        <div>
                            <h2 id="company-list-title" class="h5 fw-bold mb-1">Lista de imobiliárias</h2>
                            <p class="text-muted small mb-0">
                                Exibindo {{ $companies->firstItem() }}–{{ $companies->lastItem() }} de
                                {{ number_format($companies->total(), 0, ',', '.') }} resultados
                            </p>
                        </div>
                    </div>

                    <div class="d-none d-lg-block table-responsive" role="region" aria-label="Tabela de imobiliárias" tabindex="0">
                        <table class="table company-table align-middle mb-0">
                            <caption class="visually-hidden">Imobiliárias cadastradas: contatos, localização, código de acesso e status dos formulários.</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Imobiliária</th>
                                    <th scope="col">Documentos e contato</th>
                                    <th scope="col">Localização</th>
                                     <th scope="col">Código de acesso</th>
                                     <th scope="col">Status</th>
                                     <th scope="col">Cadastro</th>
                                    @canany(['update-real-estate-company', 'delete-real-estate-company'])
                                        <th scope="col" class="text-end">Ações</th>
                                    @endcanany
                                 </tr>
                             </thead>
                            <tbody>
                                @foreach ($companies as $company)
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <span class="company-avatar" aria-hidden="true">
                                                    {{ mb_strtoupper(mb_substr((string) $company->name, 0, 1)) }}
                                                 </span>
                                                 <div class="min-w-0">
                                                     <div class="fw-bold company-name">{{ $company->name }}</div>
                                                 </div>
                                             </div>
                                         </td>
                                        <td>
                                            <div class="company-detail-line">
                                                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                                <span>{{ $formatCnpj($company->cnpj) }}</span>
                                            </div>
                                            <div class="company-detail-line text-muted">
                                                 <i class="bi bi-telephone" aria-hidden="true"></i>
                                                 <span>{{ $formatPhone($company->phone) }}</span>
                                             </div>
                                            <div class="company-detail-line text-muted">
                                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                                <a
                                                    href="mailto:{{ $company->email }}"
                                                    class="company-email-link"
                                                    title="{{ $company->email }}"
                                                >{{ $company->email }}</a>
                                            </div>
                                         </td>
                                        <td>
                                            <div class="company-detail-line">
                                                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                                <span>{{ $location($company) }}</span>
                                            </div>
                                        </td>
                                        <td>
                                            @if (filled($company->lead_access_code))
                                                <div class="company-access-code-wrap">
                                                    <code class="company-access-code">{{ $company->lead_access_code }}</code>
                                                    <button
                                                        type="button"
                                                        class="btn company-copy-button"
                                                        data-copy-code="{{ $company->lead_access_code }}"
                                                        data-copy-feedback="copy-feedback-{{ $company->id }}"
                                                        aria-label="Copiar o código {{ $company->lead_access_code }} da imobiliária {{ $company->name }}"
                                                        title="Copiar código"
                                                    >
                                                        <i class="bi bi-copy" aria-hidden="true"></i>
                                                    </button>
                                                </div>
                                                <span id="copy-feedback-{{ $company->id }}" class="visually-hidden" aria-live="polite"></span>
                                            @else
                                                <span class="text-muted small">Indisponível</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="company-status company-status--{{ $company->lead_form_active ? 'active' : 'inactive' }}">
                                                <span aria-hidden="true"></span>
                                                {{ $company->lead_form_active ? 'Ativo' : 'Inativo' }}
                                            </span>
                                        </td>
                                        <td>
                                            <time datetime="{{ $company->created_at?->toDateString() }}">
                                                 {{ $company->created_at?->format('d/m/Y') ?? 'Não informado' }}
                                             </time>
                                         </td>
                                        @canany(['update-real-estate-company', 'delete-real-estate-company'])
                                            <td class="text-end">
                                                <div class="company-actions justify-content-end">
                                                    @can('update-real-estate-company')
                                                        <button
                                                            type="button"
                                                            class="btn company-action-button company-action-button--edit"
                                                            data-company-edit
                                                            data-company-id="{{ $company->id }}"
                                                            data-company-update-url="{{ route('admin.imobiliarias.update', ['company' => $company]) }}"
                                                            data-company-name="{{ $company->name }}"
                                                            data-company-email="{{ $company->email }}"
                                                            data-company-phone="{{ $company->phone }}"
                                                            data-company-cnpj="{{ $company->cnpj }}"
                                                            data-company-cep="{{ $company->cep }}"
                                                            data-company-city="{{ $company->city }}"
                                                            data-company-state="{{ $company->state }}"
                                                            data-company-status="{{ $company->lead_form_active ? '1' : '0' }}"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#companyEditModal"
                                                            aria-label="Editar a imobiliária {{ $company->name }}"
                                                            title="Editar"
                                                        >
                                                            <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                                            <span>Editar</span>
                                                        </button>
                                                    @endcan

                                                    @can('delete-real-estate-company')
                                                        <button
                                                            type="button"
                                                            class="btn company-action-button company-action-button--delete"
                                                            data-company-delete
                                                            data-company-delete-url="{{ route('admin.imobiliarias.destroy', ['company' => $company]) }}"
                                                            data-company-name="{{ $company->name }}"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#companyDeleteModal"
                                                            aria-label="Remover a imobiliária {{ $company->name }}"
                                                            title="Remover"
                                                        >
                                                            <i class="bi bi-trash3" aria-hidden="true"></i>
                                                        </button>
                                                    @endcan
                                                </div>
                                            </td>
                                        @endcanany
                                     </tr>
                                 @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="d-lg-none company-mobile-list">
                        @foreach ($companies as $company)
                            <article class="company-mobile-card">
                                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                    <div class="d-flex align-items-center gap-3 min-w-0">
                                        <span class="company-avatar" aria-hidden="true">
                                            {{ mb_strtoupper(mb_substr((string) $company->name, 0, 1)) }}
                                        </span>
                                         <div class="min-w-0">
                                             <h3 class="h6 fw-bold text-break mb-1">{{ $company->name }}</h3>
                                         </div>
                                     </div>
                                    <span class="company-status company-status--{{ $company->lead_form_active ? 'active' : 'inactive' }}">
                                        <span aria-hidden="true"></span>
                                        {{ $company->lead_form_active ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </div>

                                <dl class="company-mobile-details mb-3">
                                    <div>
                                        <dt>CPF/CNPJ</dt>
                                        <dd>{{ $formatCnpj($company->cnpj) }}</dd>
                                    </div>
                                     <div>
                                         <dt>Telefone</dt>
                                         <dd>{{ $formatPhone($company->phone) }}</dd>
                                     </div>
                                    <div>
                                        <dt>E-mail</dt>
                                        <dd>
                                            <a href="mailto:{{ $company->email }}" class="company-email-link">{{ $company->email }}</a>
                                        </dd>
                                    </div>
                                     <div>
                                         <dt>Localização</dt>
                                        <dd>{{ $location($company) }}</dd>
                                    </div>
                                    <div>
                                        <dt>Cadastro</dt>
                                        <dd>{{ $company->created_at?->format('d/m/Y') ?? 'Não informado' }}</dd>
                                    </div>
                                </dl>

                                <div class="company-mobile-code">
                                    <div>
                                        <span class="company-mobile-code__label">Código de acesso</span>
                                        @if (filled($company->lead_access_code))
                                            <code class="company-access-code">{{ $company->lead_access_code }}</code>
                                        @else
                                            <span class="text-muted small">Indisponível</span>
                                        @endif
                                    </div>

                                    @if (filled($company->lead_access_code))
                                        <button
                                            type="button"
                                            class="btn btn-sm company-copy-button company-copy-button--labeled"
                                            data-copy-code="{{ $company->lead_access_code }}"
                                            data-copy-feedback="copy-mobile-feedback-{{ $company->id }}"
                                            aria-label="Copiar o código {{ $company->lead_access_code }} da imobiliária {{ $company->name }}"
                                        >
                                            <i class="bi bi-copy" aria-hidden="true"></i>
                                            <span data-copy-label>Copiar</span>
                                        </button>
                                        <span id="copy-mobile-feedback-{{ $company->id }}" class="visually-hidden" aria-live="polite"></span>
                                     @endif
                                 </div>

                                @canany(['update-real-estate-company', 'delete-real-estate-company'])
                                    <div class="company-mobile-actions mt-3">
                                        @can('update-real-estate-company')
                                            <button
                                                type="button"
                                                class="btn btn-outline-primary"
                                                data-company-edit
                                                data-company-id="{{ $company->id }}"
                                                data-company-update-url="{{ route('admin.imobiliarias.update', ['company' => $company]) }}"
                                                data-company-name="{{ $company->name }}"
                                                data-company-email="{{ $company->email }}"
                                                data-company-phone="{{ $company->phone }}"
                                                data-company-cnpj="{{ $company->cnpj }}"
                                                data-company-cep="{{ $company->cep }}"
                                                data-company-city="{{ $company->city }}"
                                                data-company-state="{{ $company->state }}"
                                                data-company-status="{{ $company->lead_form_active ? '1' : '0' }}"
                                                data-bs-toggle="modal"
                                                data-bs-target="#companyEditModal"
                                            >
                                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                                Editar
                                            </button>
                                        @endcan

                                        @can('delete-real-estate-company')
                                            <button
                                                type="button"
                                                class="btn btn-outline-danger"
                                                data-company-delete
                                                data-company-delete-url="{{ route('admin.imobiliarias.destroy', ['company' => $company]) }}"
                                                data-company-name="{{ $company->name }}"
                                                data-bs-toggle="modal"
                                                data-bs-target="#companyDeleteModal"
                                            >
                                                <i class="bi bi-trash3" aria-hidden="true"></i>
                                                Remover
                                            </button>
                                        @endcan
                                    </div>
                                @endcanany
                             </article>
                        @endforeach
                    </div>

                    <footer class="company-directory-footer">
                        <p class="mb-0">{{ number_format($companies->total(), 0, ',', '.') }} {{ $companies->total() === 1 ? 'imobiliária' : 'imobiliárias' }}</p>
                        @if ($companies->hasPages())
                            <div class="company-pagination" aria-label="Paginação de imobiliárias">
                                {{ $companies->onEachSide(1)->links('pagination::bootstrap-5') }}
                            </div>
                        @endif
                    </footer>
                </div>
            </section>
        @endif
        </div>
    </div>

    @include('corretor.imobiliarias.edit', [
        'editingCompany' => $editingCompany,
        'hasEditErrors' => $hasEditErrors,
    ])
    @can('delete-real-estate-company')
        <div
            class="modal fade company-delete-modal"
            id="companyDeleteModal"
            tabindex="-1"
            aria-labelledby="company-delete-modal-title"
            aria-describedby="company-delete-modal-description"
            aria-hidden="true"
            data-company-delete-modal
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="#" data-company-delete-form>
                        @csrf
                        @method('DELETE')

                        <div class="modal-header">
                            <h2 class="modal-title fs-5" id="company-delete-modal-title">Remover imobiliária</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                        </div>

                        <div class="modal-body">
                            <div class="company-delete-warning" aria-hidden="true">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                            </div>
                            <p id="company-delete-modal-description" class="mb-2">
                                Confirma a remoção da imobiliária <strong data-delete-company-name></strong>?
                            </p>
                            <p class="text-danger fw-semibold mb-0">Esta ação é irreversível.</p>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-danger" data-company-delete-submit>
                                <span class="spinner-border spinner-border-sm me-2" data-submit-spinner aria-hidden="true" hidden></span>
                                <span data-submit-label>Sim, remover</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan
</div>
@endsection
