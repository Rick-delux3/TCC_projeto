@extends('layout-inicial.Dashboard_Admin')

@section('content_a')
@php
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Str;

    /*
    |--------------------------------------------------------------------------
    | Corretor/admin logado
    |--------------------------------------------------------------------------
    */

    $corretor = $corretor ?? auth('admin')->user();

    $isCeo = $corretor && method_exists($corretor, 'isCeo')
        ? $corretor->isCeo()
        : (($corretor->role ?? null) === 'CEO');

    /*
    |--------------------------------------------------------------------------
    | Estatísticas focadas no dashboard principal
    |--------------------------------------------------------------------------
    | O dashboard principal não exibe equipe nem tela de análises.
    | Ele mostra a base geral de clientes/leads das imobiliárias.
    */

    $dashboardStats = $dashboardStats ?? [];

    $totalLeads = $dashboardStats['totalLeads'] ?? 0;
    $newLeads = $dashboardStats['newLeads'] ?? 0;
    $recentLeads = $dashboardStats['recentLeads'] ?? 0;
    $totalImobiliarias = $dashboardStats['totalImobiliarias'] ?? 0;

    $totalAprovados = $dashboardStats['totalAprovados'] ?? 0;
    $totalRecusados = $dashboardStats['totalRecusados'] ?? 0;

    $latestLeadAt = $dashboardStats['latestLeadAt'] ?? null;

    /*
    |--------------------------------------------------------------------------
    | Dados da lista de clientes/leads
    |--------------------------------------------------------------------------
    */

    $leads = $leads ?? collect();
    $imobiliarias = $imobiliarias ?? collect();
    $leadLoversFailures = $leadLoversFailures ?? [];
    $leadLoversSyncOptions = $leadLoversSyncOptions ?? [];
    $notSentToLeadLoversCount = $notSentToLeadLoversCount ?? 0;

    $resultadoOptions = collect(
        $resultadoOptions ?? []
    );

    $manualResultOptions = $resultadoOptions
        ->map(
            fn ($definition): ?string =>
                is_array($definition)
                && filled($definition['label'] ?? null)
                    ? (string) $definition['label']
                    : null
        )
        ->filter(fn (?string $label): bool => filled($label));

    $manualResultVisuals = [
        'approved' => [
            'active' => 'text-bg-success',
            'inactive' => 'text-bg-light border border-success text-success',
            'icon' => 'bi-check-circle',
        ],
        'rejected' => [
            'active' => 'text-bg-danger',
            'inactive' => 'text-bg-light border border-danger text-danger',
            'icon' => 'bi-x-circle',
        ],
        'in_negotiation' => [
            'active' => 'text-bg-warning text-dark',
            'inactive' => 'text-bg-light border border-warning text-dark',
            'icon' => 'bi-hourglass-split',
        ],
        'rental_confirmed' => [
            'active' => 'text-bg-primary',
            'inactive' => 'text-bg-light border border-primary text-primary',
            'icon' => 'bi-house-check',
        ],
        'no_rent_or_insurance' => [
            'active' => 'text-bg-secondary',
            'inactive' => 'text-bg-light border border-secondary text-secondary',
            'icon' => 'bi-house-x',
        ],
    ];

    $defaultResultVisual = [
        'active' => 'text-bg-secondary',
        'inactive' => 'text-bg-light border border-secondary text-secondary',
        'icon' => 'bi-dash-circle',
    ];

    $manualResultRouteExists = Route::has('admin.leads.result-tag.update');
    $leadLoversIntegrationEnabled = (bool) config(
        'services.leadlovers.enabled',
        false
    );
    $insuranceAnalysisEnabled = (bool) config(
        'features.insurance_analysis.enabled',
        false
    );

    $leadValidationFields = [
        'nome',
        'email',
        'tel',
        'cpf',
        'tipo_solicitante',
        'estado_civil',
        'conjuge_nome',
        'conjuge_cpf',
        'valor_aluguel',
        'valor_agua',
        'valor_luz',
        'valor_gas',
        'valor_condominio',
        'valor_iptu',
        'outras_despesas',
        'cep',
        'estado',
        'cidade_imovel',
        'bairro',
        'logradouro',
        'numero',
        'complemento',
    ];

    $resultValidationTargets = null;
    $resultContextLeadId = (string) old('result_context_lead_id', '');
    $visibleLeads = method_exists($leads, 'getCollection')
        ? $leads->getCollection()
        : collect($leads);

    $leadContextId = (string) old('lead_context_id', '');
    $firstInvalidLeadField = filled($leadContextId)
        ? collect($leadValidationFields)->first(
            fn (string $field): bool => $errors->has($field)
        )
        : null;
    $leadValidationTargets = null;
    $leadLoversCorrectionErrors = $errors->getBag(
        'leadloversCorrection'
    );
    $leadLoversCorrectionContextId = (string) old(
        'leadlovers_correction_context_id',
        ''
    );
    $firstInvalidLeadLoversCorrectionField = collect([
        'tel',
        'email',
        'leadlovers',
    ])->first(
        fn (string $field): bool =>
            $leadLoversCorrectionErrors->has($field)
    );
    $leadLoversCorrectionValidationTargets = null;

    if (filled($leadContextId) && filled($firstInvalidLeadField)) {
        $leadContextLead = $visibleLeads->first(
            fn ($lead): bool => (string) $lead->id === $leadContextId
        );

        if ($leadContextLead) {
            $leadValidationTargets = [
                'modal' => 'adminLeadModal'.$leadContextLead->id,
                'tab' => 'admin-lead-data-tab-'.$leadContextLead->id,
                'field' => 'admin-lead-'.$leadContextLead->id.'-'.str_replace('_', '-', $firstInvalidLeadField),
            ];
        }
    }

    if (
        filled($leadLoversCorrectionContextId)
        && filled($firstInvalidLeadLoversCorrectionField)
    ) {
        $correctionContextLead = $visibleLeads->first(
            fn ($lead): bool =>
                (string) $lead->id === $leadLoversCorrectionContextId
        );

        if ($correctionContextLead) {
            $correctionFailure = $leadLoversFailures[
                (int) $correctionContextLead->id
            ] ?? null;
            $correctionField = in_array(
                $firstInvalidLeadLoversCorrectionField,
                ['tel', 'email'],
                true
            )
                ? $firstInvalidLeadLoversCorrectionField
                : null;

            if (($correctionFailure['correctable'] ?? false) === true) {
                $leadLoversCorrectionValidationTargets = [
                    'modal' => 'adminLeadLoversCorrectionModal'
                        .$correctionContextLead->id,
                    'field' => $correctionField
                        ? 'admin-leadlovers-correction-'
                            .$correctionContextLead->id.'-'.$correctionField
                        : 'adminLeadLoversCorrectionModal'
                            .$correctionContextLead->id.'GenericError',
                ];
            }
        }
    }

    if ($errors->has('result') && filled($resultContextLeadId)) {
        $resultContextLead = $visibleLeads->first(
            fn ($lead) => (string) $lead->id === $resultContextLeadId
        );

        if ($resultContextLead) {
            $resultValidationTargets = [
                'modal' => 'adminLeadModal'.$resultContextLead->id,
                'tab' => 'admin-lead-result-tab-'.$resultContextLead->id,
                'select' => 'adminLeadResultSelect'.$resultContextLead->id,
            ];
        }
    }

    $canAccessSimulationForms = $canAccessSimulationForms
        ?? ($canAcessSimulationForms ?? null)
        ?? (
            $corretor
                ? Gate::forUser($corretor)->allows('access-simulation-forms')
                : false
        );

    $canCreateAnalysis = $insuranceAnalysisEnabled
        && (
            $canCreateAnalysis
            ?? ($canStartInsuranceAnalysis ?? null)
            ?? (
                $corretor
                    ? Gate::forUser($corretor)->allows('create-analysis')
                    : false
            )
        );

    $simulationCompanies = collect(
        $simulationCompanies
            ?? $imobiliarias
            ?? []
    )
        ->filter(
            fn ($company) => (bool) data_get(
                $company,
                'lead_form_active',
                true
            )
        )
        ->sortBy(
            fn ($company) => mb_strtolower(
                (string) data_get($company, 'name', '')
            )
        )
        ->values();

    $adminSimulationRouteExists = Route::has('admin.simulations.open');

    $adminSimulationOpenRoute = $adminSimulationRouteExists
        ? route('admin.simulations.open')
        : '#';

    $leadSearch = $leadSearch ?? request('lead_name', '');
    $selectedImobiliaria = $selectedImobiliaria ?? request('imobiliaria', '');
    $selectedResultado = $selectedResultado ?? request('resultado', '');
    $selectedTipoSolicitante = $selectedTipoSolicitante
        ?? request('tipo_solicitante', '');
    $selectedLeadLoversSync = $selectedLeadLoversSync
        ?? request('leadlovers_sync', '');

    $tipoSolicitantesOptions = $tipoSolicitantesOptions ?? [
        'imobiliaria_cadastrada' => 'Imobiliária cadastrada',
        'imobiliaria_nao_cadastrada' => 'Imobiliária não cadastrada',
        'locador' => 'Proprietário / locador',
        'locatario' => 'Locatário',
    ];

    $filteredLeads = method_exists($leads, 'total')
        ? $leads->total()
        : $leads->count();

    $currentStart = method_exists($leads, 'firstItem')
        ? ($leads->firstItem() ?? 0)
        : 0;

    $currentEnd = method_exists($leads, 'lastItem')
        ? ($leads->lastItem() ?? 0)
        : $filteredLeads;

    $isFiltering = filled($leadSearch)
        || filled($selectedImobiliaria)
        || filled($selectedResultado)
        || filled($selectedTipoSolicitante)
        || filled($selectedLeadLoversSync);

    /*
    |--------------------------------------------------------------------------
    | Rotas
    |--------------------------------------------------------------------------
    */

    $dashboardRoute = Route::has('Dashboard-Admin')
        ? route('Dashboard-Admin')
        : url()->current();

    $adminUpdateLeadRoute = function($lead) {
        return Route::has('admin.leads.update') ? route('admin.leads.update', $lead) : '#';
    };

    $adminLeadLoversCorrectionRoute = function ($lead) {
        return Route::has('admin.leads.leadlovers.correct')
            ? route('admin.leads.leadlovers.correct', $lead)
            : '#';
    };

    /*
    |--------------------------------------------------------------------------
    | A rota de análises fica separada.
    | Use a rota real do seu projeto quando ajustar o controller de análises admin.
    |--------------------------------------------------------------------------
    */

    $analisesRoute = Route::has('admin.insurance-analyses.index')
        ? route('admin.insurance-analyses.index')
        : (Route::has('insurance-analyses.index') ? route('insurance-analyses.index') : '#');

    $solicitarAnaliseRoute = function ($lead) {
        if (Route::has('admin.insurance-analyses.create')) {
            return route('admin.insurance-analyses.create', ['lead' => $lead->id]);
        }

        if (Route::has('insurance-analyses.create')) {
            return route('insurance-analyses.create', ['lead' => $lead->id]);
        }

        if (Route::has('admin.leads.reanalyze')) {
            return route('admin.leads.reanalyze', $lead);
        }

        return '#';
    };

    /*
    |--------------------------------------------------------------------------
    | Labels e funções visuais
    |--------------------------------------------------------------------------
    */


    if ($selectedImobiliaria === 'sem_vinculo') {
        $selectedImobiliariaModel = null;
        $selectedImobiliariaName = 'Sem imobiliária vinculada';
    } else {
        $selectedImobiliariaModel = filled($selectedImobiliaria)
            ? $imobiliarias->firstWhere('id', (int) $selectedImobiliaria)
            : null;

        $selectedImobiliariaName = $selectedImobiliariaModel?->name
            ?? $selectedImobiliariaModel?->nome
            ?? null;
    }

    $normalizeTag = function ($value) {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replace(['_', '-'], ' ')
            ->squish()
            ->toString();
    };

    $getLeadResultTone = function ($tags) use ($normalizeTag) {
        $normalizedTags = collect($tags)
            ->map(fn ($tag) => $normalizeTag($tag))
            ->filter();

        $matchesRentalConfirmed = $normalizedTags->contains(
            fn (string $tag): bool =>
                in_array($tag, [
                    'fechado aluguel',
                    'aluguel fechado',
                    'fechado alguel',
                ], true)
        );

        $matchesNoRentOrInsurance = $normalizedTags->contains(
            fn (string $tag): bool =>
                in_array($tag, [
                    'nao aluguei nem seguro',
                    'nao aluguel nem seguro',
                ], true)
        );

        $matchesNegotiation = $normalizedTags->contains(
            fn (string $tag): bool => $tag === 'em negociacao'
        );

        $matchesRejected = $normalizedTags->contains(
            fn (string $tag): bool =>
                str_contains($tag, 'recusad')
                || str_contains($tag, 'reprovad')
                || $tag === 'ruim'
        );

        $matchesApproved = $normalizedTags->contains(
            fn (string $tag): bool => str_contains($tag, 'aprovad')
        );

        /*
         * Prioridade visual determinística para dados legados inconsistentes:
         * fechado aluguel, não aluguei nem seguro, negociação, recusado e aprovado.
         */
        $tones = [
            [
                'matches' => $matchesRentalConfirmed,
                'tone' => [
                    'label' => 'Fechado aluguel',
                    'badge' => 'text-bg-primary',
                    'card' => 'lead-card--rent-closed',
                    'icon' => 'bi-house-check',
                ],
            ],

            [
                'matches' => $matchesNoRentOrInsurance,
                'tone' => [
                    'label' => 'Não aluguei nem seguro',
                    'badge' => 'text-bg-secondary',
                    'card' => 'lead-card--no-rent-or-insurance',
                    'icon' => 'bi-house-x',
                ],
            ],

            [
                'matches' => $matchesNegotiation,
                'tone' => [
                    'label' => 'Em negociação',
                    'badge' => 'text-bg-warning',
                    'card' => 'lead-card--negotiation',
                    'icon' => 'bi-hourglass-split',
                ],
            ],
            [
                'matches' => $matchesRejected,
                'tone' => [
                    'label' => 'Recusado',
                    'badge' => 'text-bg-danger',
                    'card' => 'lead-card--bad',
                    'icon' => 'bi-x-circle',
                ],
            ],
            [
                'matches' => $matchesApproved,
                'tone' => [
                    'label' => 'Aprovado',
                    'badge' => 'text-bg-success',
                    'card' => 'lead-card--approved',
                    'icon' => 'bi-check-circle',
                ],
            ],
        ];

        foreach ($tones as $toneConfig) {
            if ($toneConfig['matches']) {
                return $toneConfig['tone'];
            }
        }

        return [
            'label' => 'Sem resultado',
            'badge' => 'text-bg-secondary',
            'card' => 'lead-card--neutral',
            'icon' => 'bi-dash-circle',
        ];
    };

    $getImobiliariaName = function ($lead) {
        if ($lead->company_id) {
            return $lead->imobiliariaVinculada?->name
                ?? $lead->imobiliariaVinculada?->nome
                ?? (is_string($lead->imobiliaria) ? $lead->imobiliaria : null)
                ?? 'Imobiliária vinculada';
        }

        if ($lead->tipo_solicitante === 'imobiliaria_nao_cadastrada') {
            return $lead->imobiliariaInformada?->nome_imobiliaria_informada
                ?? (is_string($lead->imobiliaria) ? $lead->imobiliaria : null)
                ?? 'Imobiliária não cadastrada';
        }

        return 'Sem imobiliária vinculada';
    };

    $getTipoSolicitanteLabel = function ($lead) use ($tipoSolicitantesOptions) {
        return $tipoSolicitantesOptions[$lead->tipo_solicitante]
            ?? 'Perfil não informado';
    };

    $getLeadCity = function ($lead) {
        return $lead->endereco?->cidade_imovel
            ?? $lead->cidade_imovel
            ?? 'Cidade não informada';
    };
@endphp

<div id="dashboardThemeRoot" class="dashboard-shell" data-dashboard-theme="light">
    <div class="container-fluid px-3 px-lg-4 py-4">
        <x-dashboard-realtime-notice />

        @if (session('success'))
            <div class="alert alert-success rounded-4 border-0 shadow-sm" role="status" aria-live="polite">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-warning rounded-4 border-0 shadow-sm" role="alert">
                {{ session('error') }}
            </div>
        @endif

        @if (filled($firstInvalidLeadField) && $leadValidationTargets === null)
            <div class="alert alert-danger rounded-4 border-0 shadow-sm" role="alert">
                Não foi possível associar os erros ao lead exibido nesta página.
            </div>
        @endif

        @if (
            filled($firstInvalidLeadLoversCorrectionField)
            && $leadLoversCorrectionValidationTargets === null
        )
            <div class="alert alert-danger rounded-4 border-0 shadow-sm" role="alert">
                {{ $leadLoversCorrectionErrors->first(
                    $firstInvalidLeadLoversCorrectionField
                ) }}
            </div>
        @endif

        @if ($errors->has('result') && $resultValidationTargets === null)
            <div class="alert alert-danger rounded-4 border-0 shadow-sm" role="alert">
                {{ $errors->first('result') }}
            </div>
        @endif

        {{-- Cabeçalho do conteúdo --}}
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
            <div>
                <span class="badge text-bg-primary-subtle text-primary border border-primary-subtle mb-2">
                    {{ $isCeo ? 'Dashboard do CEO' : 'Dashboard do corretor' }}
                </span>

                <h1 class="h2 fw-bold mb-1">
                    Central de leads
                </h1>

                <p class="text-muted mb-0">
                    Visualize leads de imobiliárias cadastradas e solicitações públicas, filtrando por vínculo, perfil e resultado.
                </p>
            </div>

            <div class="d-flex flex-column flex-sm-row gap-2">
                @if ($insuranceAnalysisEnabled)
                    @can('view-analyses')
                        <a href="{{ $analisesRoute }}" class="btn btn-outline-primary">
                            <i class="bi bi-clipboard2-data me-1" aria-hidden="true"></i>
                            Visualizar análises
                        </a>
                    @endcan
                @endif

                <button type="button" class="btn btn-outline-secondary" id="dashboardThemeToggle">
                    Modo escuro
                </button>
            </div>
        </div>

        {{-- Hero principal --}}
        <div class="row g-4 mb-4">
            <div class="col-12 {{ $canAccessSimulationForms ? 'col-xl-7' : '' }}">
                <div class="card border-0 shadow-sm rounded-5 dashboard-hero-card text-white">
                    <div class="card-body p-4 p-lg-5">
                        <div class="row g-4 align-items-end">
                            <div class="col-12 col-lg-8">
                                <span class="badge bg-white text-primary mb-3">
                                    Visão geral
                                </span>

                                <h2 class="display-6 fw-bold dashboard-hero-title mb-3">
                                    Base geral de clientes da corretora.
                                </h2>

                                <p class="text-white-50 mb-4">
                                    Acompanhe os diferentes status comerciais dos leads.
                                </p>

                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $totalLeads }} clientes/leads
                                    </span>

                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $totalImobiliarias }} imobiliárias
                                    </span>

                                    <span class="badge bg-white bg-opacity-10 border border-white border-opacity-25 py-2 px-3">
                                        {{ $recentLeads }} recentes
                                    </span>
                                </div>
                            </div>

                            <div class="col-12 col-lg-4">
                                <div class="bg-white bg-opacity-10 rounded-4 p-3 border border-white border-opacity-25">
                                    <div class="small text-white-50 mb-1">
                                        Última entrada
                                    </div>

                                    <div class="fw-bold">
                                        {{ $latestLeadAt ? $latestLeadAt->format('d/m/Y H:i') : 'Sem clientes cadastrados' }}
                                    </div>

                                    <hr class="border-white border-opacity-25">

                                    <div class="small text-white-50 mb-1">
                                        Perfil de acesso
                                    </div>

                                    <span class="badge {{ $isCeo ? 'text-bg-light text-primary' : 'text-bg-secondary' }}">
                                        {{ $corretor->role ?? 'integrante' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @if ($canAccessSimulationForms)
                <div class="col-12 col-xl-5">
                    <div class="card border-0 shadow-sm rounded-5 h-100 dashboard-stat-card">
                        <div class="card-body p-4 p-lg-5 d-flex flex-column">
                            <div>
                                <span class="badge text-bg-primary-subtle text-primary mb-3">
                                    Acesso rápido
                                </span>

                                <h2 class="h3 fw-bold mb-3">
                                    Novo lead
                                </h2>

                                <p class="text-muted mb-3">
                                    Escolha o vínculo e abra o formulário adequado para cadastrar um novo lead.
                                </p>

                                <p class="small text-muted mb-4">
                                    <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
                                    O corretor não precisa digitar nem visualizar a chave de acesso da imobiliária.
                                </p>
                            </div>

                            <div class="mt-auto">
                                <button
                                    type="button"
                                    class="btn btn-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#adminSimulationModal"
                                    @disabled(! $adminSimulationRouteExists)
                                >
                                    <i class="bi bi-clipboard-plus me-1" aria-hidden="true"></i>
                                    {{ $adminSimulationRouteExists ? 'Abrir formulário' : 'Formulário indisponível' }}
                                </button>

                                @unless ($adminSimulationRouteExists)
                                    <div class="form-text">
                                        Recurso temporariamente indisponível.
                                    </div>
                                @endunless
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Métricas focadas somente em clientes --}}
        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-primary-subtle text-primary">
                                Clientes
                            </span>
                            <span class="text-primary fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalLeads }}
                        </div>

                        <div class="text-muted small">
                            leads na base geral
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-success-subtle text-success">
                                Aprovados
                            </span>
                            <span class="text-success fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalAprovados }}
                        </div>

                        <div class="text-muted small">
                            clientes aprovados
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-danger-subtle text-danger">
                                Recusados
                            </span>
                            <span class="text-danger fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalRecusados }}
                        </div>

                        <div class="text-muted small">
                            recusados ou reprovados
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-4 dashboard-stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <span class="badge text-bg-info-subtle text-info">
                                Imobiliárias
                            </span>
                            <span class="text-info fw-bold">●</span>
                        </div>

                        <div class="h2 fw-bold mb-0">
                            {{ $totalImobiliarias }}
                        </div>

                        <div class="text-muted small">
                            parceiras cadastradas
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Filtros --}}
        <div class="card border-0 shadow-sm rounded-5 mb-4" id="leads-section">
            <div class="card-body p-4">
                <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-start gap-3 mb-4">
                    <div>
                        <span class="badge text-bg-secondary mb-2">
                            Filtros
                        </span>

                        <h2 class="h4 fw-bold mb-1">
                            Lista de leads
                        </h2>

                        <p class="text-muted mb-0">
                            Combine busca, vínculo com imobiliária, perfil do solicitante e resultado.
                        </p>
                    </div>

                    <div class="text-xl-end">
                        <div class="fw-bold">
                            {{ $filteredLeads }} lead(s)
                        </div>

                        <div class="small text-muted">
                            {{ $isFiltering ? 'Resultado filtrado' : 'Base geral' }}
                        </div>
                    </div>
                </div>

                @can('view-leads')
                    <form method="GET" action="{{ $dashboardRoute }}#leads-section">
                        <div class="row g-3 align-items-end">

                            {{-- Busca --}}
                            <div class="col-12 col-md-6 col-xl-2">
                                <label for="admin-lead-search" class="form-label small text-muted">
                                    Buscar lead
                                </label>

                                <div class="input-group">
                                    <span class="input-group-text bg-white">
                                        <i class="bi bi-search" aria-hidden="true"></i>
                                    </span>

                                    <input
                                        type="text"
                                        id="admin-lead-search"
                                        name="lead_name"
                                        class="form-control"
                                        value="{{ $leadSearch }}"
                                        placeholder="Nome, e-mail, CPF ou telefone"
                                        autocomplete="off"
                                    >
                                </div>
                            </div>

                            {{-- Vínculo com imobiliária --}}
                            <div class="col-12 col-md-6 col-xl-2">
                                <label for="admin-imobiliaria-filter" class="form-label small text-muted">
                                    Vínculo com imobiliária
                                </label>

                                <select id="admin-imobiliaria-filter" name="imobiliaria" class="form-select">
                                    <option value="">Todos os vínculos</option>

                                    <option
                                        value="sem_vinculo"
                                        @selected($selectedImobiliaria === 'sem_vinculo')
                                    >
                                        Sem imobiliária vinculada
                                    </option>

                                    @foreach ($imobiliarias as $imobiliaria)
                                        @php
                                            $imobiliariaName = $imobiliaria->name
                                                ?? $imobiliaria->nome
                                                ?? 'Imobiliária #' . $imobiliaria->id;
                                        @endphp

                                        <option
                                            value="{{ $imobiliaria->id }}"
                                            @selected((string) $selectedImobiliaria === (string) $imobiliaria->id)
                                        >
                                            {{ $imobiliariaName }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Perfil do solicitante --}}
                            <div class="col-12 col-md-6 col-xl-3">
                                <label for="admin-tipo-solicitante-filter" class="form-label small text-muted">
                                    Perfil do solicitante
                                </label>

                                <select
                                    id="admin-tipo-solicitante-filter"
                                    name="tipo_solicitante"
                                    class="form-select"
                                >
                                    <option value="">Todos os perfis</option>

                                    @foreach ($tipoSolicitantesOptions as $value => $label)
                                        <option
                                            value="{{ $value }}"
                                            @selected($selectedTipoSolicitante === $value)
                                        >
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Resultado --}}
                            <div class="col-12 col-md-6 col-xl-2">
                                <label for="admin-resultado-filter" class="form-label small text-muted">
                                    Resultado/tag
                                </label>

                                <select
                                    id="admin-resultado-filter"
                                    name="resultado"
                                    class="form-select"
                                >
                                    <option value="">Todos</option>

                                    @foreach ($manualResultOptions as $result => $label)
                                        <option
                                            value="{{ $result }}"
                                            @selected($selectedResultado === $result)
                                        >
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Envio inicial para a LeadLovers --}}
                            <div class="col-12 col-md-6 col-xl-2">
                                <label for="admin-leadlovers-sync-filter" class="form-label small text-muted">
                                    Envio à LeadLovers
                                </label>

                                <select
                                    id="admin-leadlovers-sync-filter"
                                    name="leadlovers_sync"
                                    class="form-select"
                                >
                                    <option value="">Todos</option>

                                    @foreach ($leadLoversSyncOptions as $value => $label)
                                        <option
                                            value="{{ $value }}"
                                            @selected($selectedLeadLoversSync === $value)
                                        >
                                            {{ $label }} ({{ $notSentToLeadLoversCount }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Botão --}}
                            <div class="col-12 col-md-6 col-xl-1 d-grid">
                                <button type="submit" class="btn btn-primary" title="Filtrar">
                                    <i class="bi bi-funnel" aria-hidden="true"></i>
                                    <span class="visually-hidden">Aplicar filtros</span>
                                </button>
                            </div>
                        </div>
                    </form>

                    {{-- Filtros ativos --}}
                    @if ($isFiltering)
                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <span class="badge rounded-pill text-bg-light border text-muted px-3 py-2">
                                Filtros ativos:
                            </span>

                            @if (filled($leadSearch))
                                <a
                                    href="{{ request()->fullUrlWithQuery(['lead_name' => null, 'page' => 1]) }}#leads-section"
                                    class="badge rounded-pill text-bg-primary text-decoration-none px-3 py-2"
                                >
                                    Busca: {{ $leadSearch }}
                                    <i class="bi bi-x ms-1" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (filled($selectedImobiliaria))
                                <a
                                    href="{{ request()->fullUrlWithQuery(['imobiliaria' => null, 'page' => 1]) }}#leads-section"
                                    class="badge rounded-pill text-bg-success text-decoration-none px-3 py-2"
                                >
                                    Vínculo: {{ $selectedImobiliariaName ?? 'Selecionado' }}
                                    <i class="bi bi-x ms-1" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (filled($selectedTipoSolicitante))
                                <a
                                    href="{{ request()->fullUrlWithQuery(['tipo_solicitante' => null, 'page' => 1]) }}#leads-section"
                                    class="badge rounded-pill text-bg-info text-decoration-none px-3 py-2"
                                >
                                    Perfil: {{ $tipoSolicitantesOptions[$selectedTipoSolicitante] ?? $selectedTipoSolicitante }}
                                    <i class="bi bi-x ms-1" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (filled($selectedResultado))
                                @php
                                    $selectedResultVisual = $manualResultVisuals[$selectedResultado]
                                        ?? $defaultResultVisual;
                                    $selectedResultLabel = $manualResultOptions->get($selectedResultado);
                                @endphp
                                <a
                                    href="{{ request()->fullUrlWithQuery(['resultado' => null, 'page' => 1]) }}#leads-section"
                                    class="badge rounded-pill {{ $selectedResultVisual['active'] }} text-decoration-none px-3 py-2"
                                    aria-label="Remover filtro de resultado {{ $selectedResultLabel }}"
                                >
                                    <i class="bi {{ $selectedResultVisual['icon'] }} me-1" aria-hidden="true"></i>
                                    Resultado: {{ $selectedResultLabel }}
                                    <i class="bi bi-x ms-1" aria-hidden="true"></i>
                                </a>
                            @endif

                            @if (filled($selectedLeadLoversSync))
                                <a
                                    href="{{ request()->fullUrlWithQuery(['leadlovers_sync' => null, 'page' => 1]) }}#leads-section"
                                    class="badge rounded-pill text-bg-danger text-decoration-none px-3 py-2"
                                    aria-label="Remover filtro de envio à LeadLovers {{ $leadLoversSyncOptions[$selectedLeadLoversSync] ?? $selectedLeadLoversSync }}"
                                >
                                    LeadLovers: {{ $leadLoversSyncOptions[$selectedLeadLoversSync] ?? $selectedLeadLoversSync }}
                                    <i class="bi bi-x ms-1" aria-hidden="true"></i>
                                </a>
                            @endif
                        </div>

                        <div class="mt-3">
                            <a href="{{ $dashboardRoute }}#leads-section" class="btn btn-sm btn-outline-secondary">
                                Limpar todos os filtros
                            </a>
                        </div>
                    @endif

                    {{-- Atalhos rápidos que preservam busca, vínculo e perfil --}}
                    <div class="d-flex flex-wrap gap-2 mt-4 pt-3 border-top">
                        <span class="small text-muted me-1">
                            Refinar rapidamente:
                        </span>

                        <a
                            href="{{ request()->fullUrlWithQuery(['resultado' => null, 'page' => 1]) }}#leads-section"
                            class="badge rounded-pill text-decoration-none px-3 py-2 {{ blank($selectedResultado) ? 'text-bg-dark' : 'text-bg-light border text-muted' }}"
                            @if (blank($selectedResultado)) aria-current="true" @endif
                        >
                            Todos
                            @if (blank($selectedResultado))
                                <span class="visually-hidden"> (selecionado)</span>
                            @endif
                        </a>

                        @foreach ($manualResultOptions as $result => $label)
                            @php
                                $resultVisual = $manualResultVisuals[$result]
                                    ?? $defaultResultVisual;
                                $resultIsSelected = $selectedResultado === $result;
                            @endphp
                            <a
                                href="{{ request()->fullUrlWithQuery(['resultado' => $result, 'page' => 1]) }}#leads-section"
                                class="badge rounded-pill text-decoration-none px-3 py-2 d-inline-flex align-items-center gap-1 {{ $resultIsSelected ? $resultVisual['active'] : $resultVisual['inactive'] }}"
                                @if ($resultIsSelected) aria-current="true" @endif
                            >
                                <i class="bi {{ $resultVisual['icon'] }}" aria-hidden="true"></i>
                                <span>{{ $label }}</span>
                                @if ($resultIsSelected)
                                    <span class="visually-hidden"> (selecionado)</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="alert alert-warning rounded-4 mb-0">
                        Você não possui permissão para visualizar leads.
                    </div>
                @endcan
            </div>
        </div>

        {{-- Lista de leads --}}
        @can('view-leads')
            @if ($filteredLeads > 0)
                <div class="lead-list vstack gap-3">
                    @foreach ($leads as $lead)
                        @php
                            $leadName = $lead->nome ?: 'Cliente sem nome';
                            $leadEmail = $lead->email ?: 'E-mail não informado';
                            $leadPhone = $lead->tel ?: 'Telefone não informado';
                            $leadCity = $getLeadCity($lead);

                            $leadDate = $lead->created_at ? $lead->created_at->format('d/m/Y') : 'Sem data';
                            $leadTime = $lead->created_at ? $lead->created_at->format('H:i') : '--:--';

                            $allTags = collect(preg_split('/\s*,\s*/', $lead->tags_originais ?? ''))
                                ->filter(fn ($tag) => filled($tag))
                                ->map(fn ($tag) => trim($tag));

                            $resultTone = $getLeadResultTone($allTags);

                            $visibleTags = $allTags->take(3);
                            $remainingTags = max($allTags->count() - $visibleTags->count(), 0);

                            $leadInitials = collect(preg_split('/\s+/', trim($leadName)))
                                ->filter()
                                ->take(2)
                                ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                                ->implode('');

                            $imobiliariaName = $getImobiliariaName($lead);
                            $tipoSolicitanteLabel = $getTipoSolicitanteLabel($lead);
                            $leadLoversFailure = $leadLoversFailures[
                                (int) $lead->id
                            ] ?? app(
                                \App\Support\LeadLoversInitialFailureCatalog::class
                            )->describe($lead);
                            $leadLoversFailureIsCorrectable =
                                $leadLoversFailure['correctable']
                                && $leadLoversFailure['fields'] !== [];
                        @endphp

                        <article class="card border-0 shadow-sm rounded-5 lead-card lead-list-item {{ $resultTone['card'] }}">
                            <div class="card-body p-3 p-lg-4">
                                <div class="row g-3 align-items-center">

                                    {{-- Cliente --}}
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="lead-avatar rounded-4 bg-primary text-white d-flex align-items-center justify-content-center fw-bold">
                                                {{ $leadInitials ?: 'C' }}
                                            </div>

                                            <div class="min-w-0">
                                                <h3 class="h6 fw-bold mb-1 text-truncate">
                                                    {{ $leadName }}
                                                </h3>

                                                <div class="small text-muted text-truncate">
                                                    {{ $leadCity }}
                                                </div>

                                                <span class="badge text-bg-info mt-1">
                                                    {{ $tipoSolicitanteLabel }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Vínculo/origem --}}
                                    <div class="col-12 col-md-6 col-xl-3">
                                        <div class="small text-muted">
                                            Vínculo/origem
                                        </div>

                                        <div class="fw-semibold text-truncate">
                                            {{ $imobiliariaName }}
                                        </div>

                                        @if ($lead->tipo_solicitante === 'imobiliaria_nao_cadastrada')
                                            <div class="small text-muted">
                                                Nome informado no formulário; sem vínculo cadastrado.
                                            </div>
                                        @elseif ($lead->tipo_solicitante === 'locador' && filled($lead->locador?->nome))
                                            <div class="small text-muted text-truncate">
                                                Proprietário: {{ $lead->locador->nome }}
                                            </div>
                                        @endif
                                    </div>

                                    {{-- E-mail --}}
                                    <div class="col-12 col-md-6 col-xl-2">
                                        <div class="small text-muted">
                                            E-mail
                                        </div>

                                        @if ($lead->email)
                                            <a href="mailto:{{ $lead->email }}" class="fw-semibold text-decoration-none text-truncate d-block">
                                                {{ $leadEmail }}
                                            </a>
                                        @else
                                            <span class="fw-semibold text-truncate d-block">
                                                {{ $leadEmail }}
                                            </span>
                                        @endif
                                    </div>

                                    {{-- Telefone --}}
                                    <div class="col-6 col-md-3 col-xl-1">
                                        <div class="small text-muted">
                                            Telefone
                                        </div>

                                        <div class="fw-semibold text-truncate">
                                            {{ $leadPhone }}
                                        </div>
                                    </div>

                                    {{-- Entrada --}}
                                    <div class="col-6 col-md-3 col-xl-1">
                                        <div class="small text-muted">
                                            Entrada
                                        </div>

                                        <div class="fw-semibold">
                                            {{ $leadDate }}
                                        </div>

                                        <div class="small text-muted">
                                            {{ $leadTime }}
                                        </div>
                                    </div>

                                    {{-- Resultado --}}
                                    <div class="col-6 col-md-4 col-xl-1">
                                        <div class="small text-muted">
                                            Resultado
                                        </div>

                                        <span class="badge {{ $resultTone['badge'] }}">
                                            <i class="bi {{ $resultTone['icon'] }} me-1" aria-hidden="true"></i>
                                            {{ $resultTone['label'] }}
                                        </span>
                                    </div>

                                    {{-- Botões --}}
                                    <div class="col-12 col-md-4 col-xl-1">
                                        @can('edit-leads')
                                            <button
                                                type="button"
                                                class="btn btn-sm {{ $leadLoversFailureIsCorrectable ? 'btn-danger leadlovers-correction-trigger' : 'btn-outline-primary' }} w-100 text-nowrap mb-2"
                                                data-bs-toggle="modal"
                                                data-bs-target="{{ $leadLoversFailureIsCorrectable ? '#adminLeadLoversCorrectionModal'.$lead->id : '#adminLeadModal'.$lead->id }}"
                                                aria-controls="{{ $leadLoversFailureIsCorrectable ? 'adminLeadLoversCorrectionModal'.$lead->id : 'adminLeadModal'.$lead->id }}"
                                                aria-haspopup="dialog"
                                                aria-label="{{ $leadLoversFailureIsCorrectable ? 'Corrigir dados de '.$leadName.' para reenvio à LeadLovers' : 'Editar lead '.$leadName }}"
                                            >
                                                <i
                                                    class="bi {{ $leadLoversFailureIsCorrectable ? 'bi-wrench-adjustable-circle' : 'bi-pencil-square' }} me-1"
                                                    aria-hidden="true"
                                                ></i>
                                                {{ $leadLoversFailureIsCorrectable ? 'Corrigir' : 'Editar' }}
                                            </button>
                                        @else
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary w-100 text-nowrap mb-2"
                                                data-bs-toggle="modal"
                                                data-bs-target="#adminLeadModal{{ $lead->id }}"
                                            >
                                                Visualizar
                                            </button>
                                        @endcan

                                        @if ($insuranceAnalysisEnabled)
                                            @can('create-analysis')
                                                @if ($solicitarAnaliseRoute($lead) !== '#')
                                                    <form method="POST" action="{{ $solicitarAnaliseRoute($lead) }}">
                                                        @csrf

                                                        <button type="submit" class="btn btn-sm btn-warning w-100 text-nowrap">
                                                            Analisar
                                                        </button>
                                                    </form>
                                                @else
                                                    <button type="button" class="btn btn-sm btn-warning w-100 text-nowrap" disabled>
                                                        Analisar
                                                    </button>
                                                @endif
                                            @endcan
                                        @endif
                                    </div>
                                </div>

                                <div class="d-flex flex-wrap align-items-center gap-2 mt-3 pt-3 border-top">
                                    <span class="small text-muted">
                                        LeadLovers:
                                    </span>
                                    @include('partials.leadlovers-sync-status', [
                                        'lead' => $lead,
                                        'failure' => $leadLoversFailure,
                                    ])

                                    @if ($visibleTags->isNotEmpty())
                                        @foreach ($visibleTags as $tag)
                                            <span class="badge rounded-pill text-bg-light border text-muted">
                                                {{ $tag }}
                                            </span>
                                        @endforeach

                                        @if ($remainingTags > 0)
                                            <span class="badge rounded-pill text-bg-secondary">
                                                +{{ $remainingTags }}
                                            </span>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                {{-- Paginação --}}
                <div class="card border-0 shadow-sm rounded-4 mt-4">
                    <div class="card-body d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                        <p class="text-muted small mb-0">
                            Exibindo {{ $currentStart }} a {{ $currentEnd }} de {{ $filteredLeads }} lead(s){{ $isFiltering ? ' filtrados' : '' }}.
                        </p>

                        @if (method_exists($leads, 'hasPages') && $leads->hasPages())
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
                            Nenhum lead encontrado
                        </span>

                        @if ($isFiltering)
                            <h3 class="h5 fw-bold">
                                Nenhum lead corresponde aos filtros atuais.
                            </h3>

                            <p class="text-muted">
                                Tente alterar a busca, o vínculo, o perfil, o resultado ou limpar os filtros.
                            </p>

                            <a href="{{ $dashboardRoute }}#leads-section" class="btn btn-outline-secondary">
                                Limpar filtros
                            </a>
                        @else
                            <h3 class="h5 fw-bold">
                                Ainda não há leads cadastrados.
                            </h3>

                            <p class="text-muted">
                                Assim que uma imobiliária ou um solicitante público enviar uma simulação, o lead aparecerá aqui.
                            </p>
                        @endif
                    </div>
                </div>
            @endif
        @endcan
    </div>
</div>

@if ($canAccessSimulationForms)
    {{-- Seleção global do formulário de novo lead --}}
    <div
        class="modal fade"
        id="adminSimulationModal"
        tabindex="-1"
        aria-labelledby="adminSimulationModalLabel"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-5 border-0 shadow-lg">
                <form
                    method="GET"
                    action="{{ $adminSimulationOpenRoute }}"
                    target="_blank"
                    id="adminSimulationSelectionForm"
                >
                    <div class="modal-header border-0 pb-0">
                        <div>
                            <h2 class="modal-title h5 fw-bold" id="adminSimulationModalLabel">
                                Escolha o formulário
                            </h2>
                            <p class="text-muted small mb-0 mt-2">
                                Defina se a solicitação será vinculada a uma imobiliária. O formulário será aberto em uma nova aba.
                            </p>
                        </div>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Fechar"
                        ></button>
                    </div>

                    <div class="modal-body p-4">
                        @if ($errors->adminSimulation->any())
                            <div class="alert alert-danger rounded-4">
                                <ul class="mb-0">
                                    @foreach ($errors->adminSimulation->all() as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div class="mb-3">
                            <label for="adminSimulationLinkType" class="form-label fw-semibold">
                                Vínculo
                            </label>
                            <select
                                name="vinculo"
                                id="adminSimulationLinkType"
                                class="form-select"
                                required
                            >
                                <option value="">Selecione</option>
                                <option
                                    value="imobiliaria_cadastrada"
                                    @selected(old('vinculo') === 'imobiliaria_cadastrada')
                                    @disabled($simulationCompanies->isEmpty())
                                >
                                    Vincular a uma imobiliária cadastrada
                                </option>
                                <option
                                    value="sem_vinculo"
                                    @selected(old('vinculo') === 'sem_vinculo')
                                >
                                    Não vincular a uma imobiliária
                                </option>
                            </select>
                        </div>

                        <div id="adminSimulationCompanyGroup" class="mb-3 d-none">
                            <label for="adminSimulationCompany" class="form-label fw-semibold">
                                Imobiliária
                            </label>
                            <select
                                name="company_id"
                                id="adminSimulationCompany"
                                class="form-select"
                                disabled
                            >
                                <option value="">Selecione</option>
                                @foreach ($simulationCompanies as $company)
                                    @php
                                        $companyCity = data_get($company, 'city')
                                            ?? data_get($company, 'cidade');
                                        $companyState = data_get($company, 'state')
                                            ?? data_get($company, 'uf');
                                        $companyLocation = collect([$companyCity, $companyState])
                                            ->filter(fn ($value) => filled($value))
                                            ->implode('/');
                                    @endphp
                                    <option
                                        value="{{ data_get($company, 'id') }}"
                                        @selected((string) old('company_id') === (string) data_get($company, 'id'))
                                    >
                                        {{ data_get($company, 'name', data_get($company, 'nome', 'Imobiliária')) }}{{ $companyLocation ? ' — '.$companyLocation : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                O tipo será registrado automaticamente como imobiliária cadastrada.
                            </div>
                        </div>

                        <div id="adminSimulationTypeGroup" class="mb-3 d-none">
                            <label for="adminSimulationType" class="form-label fw-semibold">
                                Tipo do solicitante
                            </label>
                            <select
                                name="tipo_solicitante"
                                id="adminSimulationType"
                                class="form-select"
                                disabled
                            >
                                <option value="">Selecione</option>
                                <option
                                    value="imobiliaria_nao_cadastrada"
                                    @selected(old('tipo_solicitante') === 'imobiliaria_nao_cadastrada')
                                >
                                    Imobiliária não cadastrada
                                </option>
                                <option value="locatario" @selected(old('tipo_solicitante') === 'locatario')>
                                    Locatário
                                </option>
                                <option value="locador" @selected(old('tipo_solicitante') === 'locador')>
                                    Proprietário / locador
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="modal-footer border-0 pt-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" @disabled(! $adminSimulationRouteExists)>
                            Abrir formulário
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const linkType = document.getElementById('adminSimulationLinkType');
            const companyGroup = document.getElementById('adminSimulationCompanyGroup');
            const company = document.getElementById('adminSimulationCompany');
            const typeGroup = document.getElementById('adminSimulationTypeGroup');
            const type = document.getElementById('adminSimulationType');

            if (!linkType || !companyGroup || !company || !typeGroup || !type) {
                return;
            }

            const updateSimulationFields = function () {
                const usesRegisteredCompany = linkType.value === 'imobiliaria_cadastrada';
                const hasNoCompanyLink = linkType.value === 'sem_vinculo';

                companyGroup.classList.toggle('d-none', !usesRegisteredCompany);
                company.disabled = !usesRegisteredCompany;
                company.required = usesRegisteredCompany;

                typeGroup.classList.toggle('d-none', !hasNoCompanyLink);
                type.disabled = !hasNoCompanyLink;
                type.required = hasNoCompanyLink;
            };

            updateSimulationFields();
            linkType.addEventListener('change', updateSimulationFields);

            @if ($errors->adminSimulation->any())
                const modalElement = document.getElementById('adminSimulationModal');

                if (modalElement && window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
                }
            @endif
        });
    </script>
@endif

{{-- Modais dos leads --}}
@can('view-leads')
    @foreach ($leads as $lead)
        @php
            $leadName = $lead->nome ?: 'Cliente sem nome';

            $imobiliariaName = $getImobiliariaName($lead);
            $tipoSolicitanteLabel = $getTipoSolicitanteLabel($lead);

            $allTags = collect(preg_split('/\s*,\s*/', $lead->tags_originais ?? ''))
                ->filter(fn ($tag) => filled($tag))
                ->map(fn ($tag) => trim($tag));

            $resultTone = $getLeadResultTone($allTags);

            $lastAnalysis = $insuranceAnalysisEnabled
                ? $lead->insuranceAnalyses
                    ->sortByDesc('created_at')
                    ->first()
                : null;

            $canReanalyze = $insuranceAnalysisEnabled
                && filled($lead->reanalysis_unlocked_at)
                && (! $lastAnalysis
                    || $lead->reanalysis_unlocked_at->gt($lastAnalysis->created_at));

            $leadHasRemoteId = (int) $lead->leadlovers_lead_id > 0;

            $leadWasConfirmedByLeadLovers = $lead->leadlovers_status === 'sent'
                && filled($lead->sent_to_leadlovers_at);

            $leadResultIsEligible = $manualResultRouteExists
                && $leadLoversIntegrationEnabled
                && $leadWasConfirmedByLeadLovers
                && $leadHasRemoteId;

            $leadResultUnavailableMessage = match (true) {
                ! $manualResultRouteExists =>
                    'A alteração de resultado está temporariamente indisponível.',
                ! $leadLoversIntegrationEnabled =>
                    'A integração com a LeadLovers está desativada.',
                ! $leadWasConfirmedByLeadLovers =>
                    'Este lead ainda não foi confirmado na LeadLovers.',
                ! $leadHasRemoteId =>
                    'O lead não possui um ID remoto válido da LeadLovers.',
                default => null,
            };

            $isResultContextLead = $errors->has('result')
                && $resultContextLeadId === (string) $lead->id;

            $resultErrorMessage = $isResultContextLead
                ? $errors->first('result')
                : null;

            $isLeadValidationContext = filled($firstInvalidLeadField)
                && $leadContextId === (string) $lead->id;
            $leadLoversFailure = $leadLoversFailures[(int) $lead->id]
                ?? app(
                    \App\Support\LeadLoversInitialFailureCatalog::class
                )->describe($lead);
            $leadLoversFailureIsCorrectable =
                $leadLoversFailure['correctable']
                && $leadLoversFailure['fields'] !== [];
            $isLeadLoversCorrectionValidationContext =
                filled($firstInvalidLeadLoversCorrectionField)
                && $leadLoversCorrectionContextId === (string) $lead->id;
            $leadCanBeGenerallyEdited = Gate::allows('edit-leads')
                && ! $leadLoversFailureIsCorrectable;
        @endphp

        <div
            class="modal fade lead-details-modal"
            id="adminLeadModal{{ $lead->id }}"
            tabindex="-1"
            aria-labelledby="adminLeadModalLabel{{ $lead->id }}"
            aria-hidden="true"
        >
            <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
                <div class="modal-content rounded-5 border-0 shadow-lg">

                    <div class="modal-header border-0 pb-0">
                        <div>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <span class="badge {{ $resultTone['badge'] }}">
                                    <i class="bi {{ $resultTone['icon'] }} me-1" aria-hidden="true"></i>
                                    {{ $resultTone['label'] }}
                                </span>

                                <span class="badge text-bg-info">
                                    {{ $tipoSolicitanteLabel }}
                                </span>

                                @include('partials.leadlovers-sync-status', [
                                    'lead' => $lead,
                                    'failure' => $leadLoversFailure,
                                ])
                            </div>

                            <h5 class="modal-title fw-bold" id="adminLeadModalLabel{{ $lead->id }}">
                                {{ $leadName }}
                            </h5>

                            <p class="text-muted small mb-0">
                                Vínculo/origem: {{ $imobiliariaName }}
                            </p>
                        </div>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>

                    <div class="modal-body p-4">

                        <ul class="nav nav-pills mb-4" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button
                                    id="admin-lead-data-tab-{{ $lead->id }}"
                                    class="nav-link active"
                                    data-bs-toggle="pill"
                                    data-bs-target="#admin-lead-data-pane-{{ $lead->id }}"
                                    type="button"
                                    role="tab"
                                    aria-controls="admin-lead-data-pane-{{ $lead->id }}"
                                    aria-selected="true"
                                >
                                    Dados do lead
                                </button>
                            </li>

                            @can('view-tags')
                                <li class="nav-item" role="presentation">
                                    <button
                                        id="admin-lead-tags-tab-{{ $lead->id }}"
                                        class="nav-link"
                                        data-bs-toggle="pill"
                                        data-bs-target="#admin-lead-tags-pane-{{ $lead->id }}"
                                        type="button"
                                        role="tab"
                                        aria-controls="admin-lead-tags-pane-{{ $lead->id }}"
                                        aria-selected="false"
                                    >
                                        Status
                                    </button>
                                </li>
                            @endcan

                            @can('manage-lead-tags')
                                <li class="nav-item" role="presentation">
                                    <button
                                        id="admin-lead-result-tab-{{ $lead->id }}"
                                        class="nav-link"
                                        data-bs-toggle="pill"
                                        data-bs-target="#admin-lead-result-pane-{{ $lead->id }}"
                                        type="button"
                                        role="tab"
                                        aria-controls="admin-lead-result-pane-{{ $lead->id }}"
                                        aria-selected="false"
                                    >
                                        Alterar status
                                    </button>
                                </li>
                            @endcan

                            @if ($insuranceAnalysisEnabled)
                                @can('create-analysis')
                                    <li class="nav-item" role="presentation">
                                        <button
                                            id="admin-lead-reanalysis-tab-{{ $lead->id }}"
                                            class="nav-link"
                                            data-bs-toggle="pill"
                                            data-bs-target="#admin-lead-reanalysis-pane-{{ $lead->id }}"
                                            type="button"
                                            role="tab"
                                            aria-controls="admin-lead-reanalysis-pane-{{ $lead->id }}"
                                            aria-selected="false"
                                        >
                                            Reanálise
                                        </button>
                                    </li>
                                @endcan
                            @endif
                        </ul>

                        <div class="tab-content">


                            <div
                                class="tab-pane fade show active"
                                id="admin-lead-data-pane-{{ $lead->id }}"
                                role="tabpanel"
                                aria-labelledby="admin-lead-data-tab-{{ $lead->id }}"
                            >
                            {{-- Dados do cliente --}}
                                @if ($leadCanBeGenerallyEdited)
                                    <form
                                        method="POST"
                                        action="{{ $adminUpdateLeadRoute($lead) }}"
                                        id="adminLeadUpdateForm{{ $lead->id }}"
                                        class="lead-update-form"
                                        data-lead-id="{{ $lead->id }}"
                                        data-lead-tab-id="admin-lead-data-tab-{{ $lead->id }}"
                                    >
                                        @csrf
                                @else
                                    <div class="alert alert-info rounded-4 d-flex align-items-start gap-2" role="status">
                                        <i class="bi bi-eye mt-1" aria-hidden="true"></i>
                                        <div>
                                            <strong>Visualização somente leitura.</strong>
                                            @if ($leadLoversFailureIsCorrectable)
                                                Use o botão <strong>Corrigir</strong> para alterar somente o campo recusado pela LeadLovers.
                                            @else
                                                Você pode consultar todos os dados deste lead, mas não possui permissão para editá-los.
                                            @endif
                                        </div>
                                    </div>

                                    <fieldset disabled aria-label="Dados do lead disponíveis somente para visualização">
                                @endcan

                                        @include('partials.leadlovers-sync-status', [
                                            'lead' => $lead,
                                            'failure' => $leadLoversFailure,
                                            'showLeadLoversBadge' => false,
                                            'showLeadLoversFailureMessage' => true,
                                            'leadLoversFailureMessageMode' => 'full',
                                        ])

                                        <div class="mt-3">
                                            @include('partials.lead-update-fields', [
                                                'lead' => $lead,
                                                'leadUpdateIdPrefix' => 'admin-lead',
                                                'isLeadValidationContext' => $isLeadValidationContext,
                                            ])
                                        </div>
                                @if ($leadCanBeGenerallyEdited)
                                    </form>
                                @else
                                    </fieldset>
                                @endif
                            </div>

                            {{-- Tags --}}
                            @can('view-tags')
                                <div
                                    class="tab-pane fade"
                                    id="admin-lead-tags-pane-{{ $lead->id }}"
                                    role="tabpanel"
                                    aria-labelledby="admin-lead-tags-tab-{{ $lead->id }}"
                                >
                                    <div class="card border rounded-4">
                                        <div class="card-body">
                                            <h6 class="fw-bold mb-1">
                                                Tags do cliente
                                            </h6>

                                            <p class="text-muted small">
                                                Tags usadas para identificar resultado, origem e segmentação.
                                            </p>

                                            <div class="d-flex flex-wrap gap-2">
                                                @forelse ($allTags as $tag)
                                                    <span class="badge rounded-pill text-bg-light border text-muted">
                                                        {{ $tag }}
                                                    </span>
                                                @empty
                                                    <span class="text-muted small">
                                                        Nenhuma tag encontrada para este cliente.
                                                    </span>
                                                @endforelse
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endcan

                            @can('manage-lead-tags')
                                <div
                                    class="tab-pane fade"
                                    id="admin-lead-result-pane-{{ $lead->id }}"
                                    role="tabpanel"
                                    aria-labelledby="admin-lead-result-tab-{{ $lead->id }}"
                                >
                                    <div class="card border rounded-4">
                                        <div class="card-body">
                                            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-3">
                                                <div>
                                                    <h6 class="fw-bold mb-1">
                                                        Status comercial
                                                    </h6>
                                                    <p class="text-muted small mb-0">
                                                        A alteração será enviada para a fila e somente será refletida no sistema depois da confirmação da LeadLovers.
                                                    </p>
                                                </div>

                                                <span class="badge {{ $resultTone['badge'] }} align-self-start">
                                                    <i class="bi {{ $resultTone['icon'] }} me-1" aria-hidden="true"></i>
                                                    Atual: {{ $resultTone['label'] }}
                                                </span>
                                            </div>

                                            @if ($leadResultUnavailableMessage)
                                                <div class="alert alert-warning rounded-4" role="alert">
                                                    <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
                                                    {{ $leadResultUnavailableMessage }}
                                                </div>
                                            @endif

                                            @if ($manualResultRouteExists)
                                                <form
                                                    method="POST"
                                                    action="{{ route('admin.leads.result-tag.update', $lead) }}"
                                                    id="adminLeadResultForm{{ $lead->id }}"
                                                    class="manual-lead-result-form"
                                                    data-result-eligible="{{ $leadResultIsEligible ? 'true' : 'false' }}"
                                                >
                                                    @csrf
                                                    @method('PATCH')

                                                    <input
                                                        type="hidden"
                                                        name="result_context_lead_id"
                                                        value="{{ $lead->id }}"
                                                    >

                                                    <div class="row g-3 align-items-end">
                                                        <div class="col-12 col-lg">
                                                            <label
                                                                for="adminLeadResultSelect{{ $lead->id }}"
                                                                class="form-label fw-semibold"
                                                            >
                                                                Novo status
                                                            </label>

                                                            <select
                                                                name="result"
                                                                id="adminLeadResultSelect{{ $lead->id }}"
                                                                class="form-select {{ $resultErrorMessage ? 'is-invalid' : '' }}"
                                                                required
                                                                aria-invalid="{{ $resultErrorMessage ? 'true' : 'false' }}"
                                                                @if ($resultErrorMessage)
                                                                    aria-describedby="adminLeadResultError{{ $lead->id }}"
                                                                @endif
                                                                @disabled(! $leadResultIsEligible && ! $resultErrorMessage)
                                                            >
                                                                <option value="">
                                                                    Selecione o novo status
                                                                </option>

                                                                @foreach ($manualResultOptions as $result => $label)
                                                                    <option
                                                                        value="{{ $result }}"
                                                                        @selected($isResultContextLead && old('result') === $result)
                                                                    >
                                                                        {{ $label }}
                                                                    </option>
                                                                @endforeach
                                                            </select>

                                                            @if ($resultErrorMessage)
                                                                <div
                                                                    id="adminLeadResultError{{ $lead->id }}"
                                                                    class="invalid-feedback d-block"
                                                                    role="alert"
                                                                >
                                                                    {{ $resultErrorMessage }}
                                                                </div>
                                                            @endif
                                                        </div>

                                                        <div class="col-12 col-lg-auto d-grid">
                                                            <button
                                                                type="submit"
                                                                class="btn btn-primary text-nowrap"
                                                                data-result-submit
                                                                @disabled(! $leadResultIsEligible)
                                                            >
                                                                <span
                                                                    class="spinner-border spinner-border-sm me-2 d-none"
                                                                    aria-hidden="true"
                                                                    data-result-spinner
                                                                ></span>
                                                                <span data-result-button-label>
                                                                    Solicitar alteração
                                                                </span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </form>
                                            @else
                                                <div class="row g-3 align-items-end">
                                                    <div class="col-12 col-lg">
                                                        <label
                                                            for="adminLeadResultSelect{{ $lead->id }}"
                                                            class="form-label fw-semibold"
                                                        >
                                                            Novo status
                                                        </label>
                                                        <select
                                                            id="adminLeadResultSelect{{ $lead->id }}"
                                                            class="form-select"
                                                            disabled
                                                        >
                                                            <option>Selecione o novo status</option>
                                                            @foreach ($manualResultOptions as $label)
                                                                <option>{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div class="col-12 col-lg-auto d-grid">
                                                        <button type="button" class="btn btn-primary text-nowrap" disabled>
                                                            Solicitar alteração
                                                        </button>
                                                    </div>
                                                </div>
                                            @endif

                                            <div class="alert alert-info rounded-4 mt-3 mb-0" role="note">
                                                <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
                                                Tags de origem, imobiliária, campanha e segmentação serão preservadas.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endcan


                            @if ($insuranceAnalysisEnabled)
                                @can('create-analysis')
                                    <div
                                        class="tab-pane fade"
                                        id="admin-lead-reanalysis-pane-{{ $lead->id }}"
                                        role="tabpanel"
                                        aria-labelledby="admin-lead-reanalysis-tab-{{ $lead->id }}"
                                    >
                                        @if ($canReanalyze)
                                            <div class="alert alert-success rounded-4">
                                                <strong>Reanálise liberada.</strong>
                                                Este cliente possui alterações salvas depois da última análise.
                                            </div>

                                            <form
                                                method="POST"
                                                action="{{ $solicitarAnaliseRoute($lead) }}"
                                            >
                                                @csrf

                                                <button
                                                    type="submit"
                                                    class="btn btn-warning"
                                                    @disabled($solicitarAnaliseRoute($lead) === '#')
                                                >
                                                    <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
                                                    Solicitar reanálise
                                                </button>
                                            </form>
                                        @else
                                            <div class="alert alert-info rounded-4">
                                                <strong>Reanálise bloqueada.</strong>
                                                Para solicitar uma nova análise, altere algum dado do cliente e clique em
                                                <strong>Salvar alterações</strong>.
                                            </div>

                                            @if ($lastAnalysis)
                                                <p class="text-muted small mb-0">
                                                    Última análise registrada em:
                                                    {{ $lastAnalysis->created_at->format('d/m/Y H:i') }}
                                                </p>
                                            @else
                                                <p class="text-muted small mb-0">
                                                    Nenhuma análise anterior foi encontrada para este cliente.
                                                </p>
                                            @endif
                                        @endif
                                    </div>
                                @endcan
                            @endif
                        </div>
                    </div>

                    <div class="modal-footer border-0 pt-0">
                        @if ($leadCanBeGenerallyEdited)
                            <button
                                type="submit"
                                form="adminLeadUpdateForm{{ $lead->id }}"
                                class="btn btn-primary"
                                data-lead-submit
                                data-lead-id="{{ $lead->id }}"
                            >
                                <span
                                    class="spinner-border spinner-border-sm me-2 d-none"
                                    aria-hidden="true"
                                    data-lead-spinner
                                ></span>
                                <span data-lead-submit-label>Salvar alterações</span>
                            </button>
                        @endif

                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Fechar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        @can('edit-leads')
            @include('partials.leadlovers-correction-modal', [
                'lead' => $lead,
                'failure' => $leadLoversFailure,
                'correctionRoute' => $adminLeadLoversCorrectionRoute($lead),
                'correctionModalIdPrefix' => 'adminLeadLoversCorrectionModal',
                'correctionFieldIdPrefix' => 'admin-leadlovers-correction',
                'isCorrectionValidationContext' => $isLeadLoversCorrectionValidationContext,
                'correctionErrors' => $leadLoversCorrectionErrors,
            ])
        @endcan
    @endforeach
@endcan

<script id="dashboardUserConfig" type="application/json">
    {!! json_encode([
        'leadValidationTargets' => $leadValidationTargets,
        'leadLoversCorrectionValidationTargets' => $leadLoversCorrectionValidationTargets,
         'realtime' => [
            'channel' => 'admins.dashboard',
            'event' => '.dashboard.activity.changed',
            'hasUnsavedInput' => session()->hasOldInput(),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>

@can('manage-lead-tags')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const resultForms = document.querySelectorAll('.manual-lead-result-form');

            const resetResultFormButton = function (form) {
                const submitButton = form.querySelector('[data-result-submit]');
                const spinner = form.querySelector('[data-result-spinner]');
                const buttonLabel = form.querySelector('[data-result-button-label]');

                delete form.dataset.submitting;

                if (submitButton) {
                    submitButton.disabled = form.dataset.resultEligible !== 'true';
                }

                spinner?.classList.add('d-none');

                if (buttonLabel) {
                    buttonLabel.textContent = 'Solicitar alteração';
                }
            };

            resultForms.forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (form.dataset.resultEligible !== 'true') {
                        event.preventDefault();
                        return;
                    }

                    if (!form.checkValidity()) {
                        return;
                    }

                    if (form.dataset.submitting === 'true') {
                        event.preventDefault();
                        return;
                    }

                    const submitButton = form.querySelector('[data-result-submit]');
                    const spinner = form.querySelector('[data-result-spinner]');
                    const buttonLabel = form.querySelector('[data-result-button-label]');

                    form.dataset.submitting = 'true';

                    if (submitButton) {
                        submitButton.disabled = true;
                    }

                    spinner?.classList.remove('d-none');

                    if (buttonLabel) {
                        buttonLabel.textContent = 'Solicitando...';
                    }

                    window.setTimeout(function () {
                        if (event.defaultPrevented) {
                            resetResultFormButton(form);
                        }
                    }, 0);
                });
            });

            window.addEventListener('pageshow', function () {
                resultForms.forEach(resetResultFormButton);
            });

            const validationTargets = @json($resultValidationTargets);

            if (
                !validationTargets
                || !window.bootstrap?.Modal
                || !window.bootstrap?.Tab
            ) {
                return;
            }

            const modalElement = document.getElementById(validationTargets.modal);
            const tabElement = document.getElementById(validationTargets.tab);
            const selectElement = document.getElementById(validationTargets.select);

            if (!modalElement || !tabElement || !selectElement) {
                return;
            }

            const revealResultError = function () {
                window.bootstrap.Tab.getOrCreateInstance(tabElement).show();

                window.setTimeout(function () {
                    selectElement.focus();
                }, 0);
            };

            if (modalElement.classList.contains('show')) {
                revealResultError();
                return;
            }

            modalElement.addEventListener(
                'shown.bs.modal',
                revealResultError,
                { once: true }
            );

            window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
        });
    </script>
@endcan

@endsection
