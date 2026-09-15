<?php

namespace App\Http\Controllers;

use App\Http\Requests\FilterCorretorDashboardRequest;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Services\CorretorDashboardLeadQuery;
use App\Support\LeadLoversInitialFailureCatalog;
use App\Support\ManualLeadResultTags;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CorretorDashboardController extends Controller
{
    public function __construct(
        private LeadLoversInitialFailureCatalog $leadLoversFailureCatalog,
        private CorretorDashboardLeadQuery $dashboardLeadQuery,
    ) {}

    public function index(FilterCorretorDashboardRequest $request): View
    {
        $corretor = Auth::guard('admin')->user();

        abort_if(! $corretor, 401, 'Corretor não autenticado.');

        $canViewLeads = Gate::forUser($corretor)->allows('view-leads');

        $canViewRealEstateCompanies = Gate::forUser($corretor)
            ->allows('view-real-estate-companies');

        $canAcessSimulationForms = Gate::forUser($corretor)
            ->allows('access-simulation-forms');

        $canStartInsuranceAnalysis = Gate::forUser($corretor)->allows('create-analysis');

        $filters = $request->validated();
        $leadSearch = $filters['lead_name'] ?? '';
        $selectedImobiliaria = $filters['imobiliaria'] ?? '';
        $selectedResultado = $filters['resultado'] ?? '';
        $selectedTipoSolicitante = $filters['tipo_solicitante'] ?? '';
        $selectedLeadLoversSync = $filters['leadlovers_sync'] ?? '';
        $tipoSolicitantesOptions = CorretorDashboardLeadQuery::requesterOptions();
        $resultadoOptions = collect(ManualLeadResultTags::all());
        $leadResultFilterOptions = $resultadoOptions->map(fn (array $definition): string => $definition['label'])->all()
            + [CorretorDashboardLeadQuery::WITHOUT_RESULT => 'Sem resultado'];
        $leadLoversSyncOptions = $this->leadLoversFailureCatalog->dashboardSyncOptions();

        $leadsQuery = $canViewLeads
            ? $this->dashboardLeadQuery->apply($this->dashboardLeadQuery->base(), $filters)
            : null;

        $notSentToLeadLoversCount = $canViewLeads
            ? Lead::query()
                ->notSentToLeadLoversBecauseOfInvalidData()
                ->count()
            : 0;

        $leads = $canViewLeads
            ? $this->dashboardLeadQuery->approvedFirst($leadsQuery)
                ->with([
                    'endereco',
                    'despesas',
                    'conjuge',
                    'lead_empresa',
                    'imobiliariaVinculada:id,name',
                    'imobiliariaInformada',
                    'locador',
                    'insuranceAnalyses' => function (HasMany $query): void {
                        if (! config('features.insurance_analysis.enabled')) {
                            $query->whereRaw('1 = 0');
                        }

                        $query->latest('created_at')->latest('id')->limit(1);
                    },
                    'leadLoversTagOperation.desiredRequestLog.corretor:id,name',
                    'leadLoversTagOperation.inflightRequestLog.corretor:id,name',
                    'latestDataUpdateRequestLog.corretor:id,name',
                ])
                ->paginate(6)
                ->appends($filters)
            : collect();

        $leadRequesterProfiles = $canViewLeads
            ? $leads->getCollection()->mapWithKeys(fn (Lead $lead): array => [
                (int) $lead->id => $this->dashboardLeadQuery->requesterProfileFor($lead),
            ])->all()
            : [];

        $leadLoversFailures = $canViewLeads
            ? $leads->getCollection()
                ->mapWithKeys(fn (Lead $lead): array => [
                    (int) $lead->id => $this->leadLoversFailureCatalog->describe($lead),
                ])
                ->all()
            : [];

        $manualLeadTagProcessingStates = $canViewLeads
            ? $leads->getCollection()
                ->mapWithKeys(function (Lead $lead): array {
                    $operation = $lead->leadLoversTagOperation;
                    $requestLog = $operation?->activeManualRequestLog();
                    $corretorName = trim((string) $requestLog?->corretor?->name);
                    $requestedResult = data_get(
                        $requestLog?->new_values,
                        'requested_result'
                    );
                    $resultLabel = trim((string) data_get(
                        $requestLog?->new_values,
                        'requested_label',
                        is_string($requestedResult)
                            ? ManualLeadResultTags::label($requestedResult)
                            : null
                    ));

                    if (
                        ! $requestLog instanceof CorretorActivityLog
                        || blank($corretorName)
                        || blank($resultLabel)
                    ) {
                        return [];
                    }

                    return [
                        (int) $lead->id => [
                            'request_id' => (int) $requestLog->id,
                            'corretor_name' => $corretorName,
                            'result_label' => $resultLabel,
                        ],
                    ];
                })
                ->all()
            : [];

        $leadDataSyncProcessingStates = $canViewLeads
            ? $leads->getCollection()
                ->mapWithKeys(function (Lead $lead): array {
                    $requestLog = $lead->latestDataUpdateRequestLog;
                    $corretorName = trim((string) $requestLog?->corretor?->name);
                    $syncVersion = (int) $lead->leadlovers_update_version;
                    $requestSyncVersion = (int) data_get(
                        $requestLog?->new_values,
                        'leadlovers_update_version'
                    );

                    if (
                        ! in_array($lead->leadlovers_update_status, [
                            'pending',
                            'processing',
                        ], true)
                        || ! $requestLog instanceof CorretorActivityLog
                        || $requestLog->action !== 'lead_data_update_requested'
                        || $requestLog->model_type !== Lead::class
                        || (int) $requestLog->model_id !== (int) $lead->id
                        || $syncVersion <= 0
                        || $requestSyncVersion !== $syncVersion
                        || blank($corretorName)
                    ) {
                        return [];
                    }

                    return [
                        (int) $lead->id => [
                            'request_id' => (int) $requestLog->id,
                            'corretor_name' => $corretorName,
                            'sync_version' => $syncVersion,
                        ],
                    ];
                })
                ->all()
            : [];

        $dashboardStats = $canViewLeads
            ? $this->dashboardLeadQuery->statistics()
            : [
                'totalLeads' => 0,
                'newLeads' => 0,
                'recentLeads' => 0,
                'totalAprovados' => 0,
                'totalRecusados' => 0,
                'latestLeadAt' => null,
            ];
        $dashboardStats['totalImobiliarias'] = $canViewRealEstateCompanies
            ? Imobiliaria::query()->count()
            : 0;

        $imobiliarias = $canViewLeads
            ? Imobiliaria::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])
            : collect();

        $simulationCompanies = $canAcessSimulationForms
            ? Imobiliaria::query()->where('lead_form_active', true)
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'city',
                    'state',
                    'lead_form_active',
                ]) : collect();

        return view('corretor.dashboard-admin', compact(
            'corretor',
            'dashboardStats',
            'leads',
            'imobiliarias',
            'leadSearch',
            'selectedImobiliaria',
            'selectedResultado',
            'resultadoOptions',
            'leadResultFilterOptions',
            'leadRequesterProfiles',
            'selectedTipoSolicitante',
            'tipoSolicitantesOptions',
            'simulationCompanies',
            'canAcessSimulationForms',
            'canStartInsuranceAnalysis',
            'selectedLeadLoversSync',
            'leadLoversSyncOptions',
            'notSentToLeadLoversCount',
            'leadLoversFailures',
            'manualLeadTagProcessingStates',
            'leadDataSyncProcessingStates',
        ));

    }
}
