<?php

namespace App\Http\Controllers;

use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Support\LeadLoversInitialFailureCatalog;
use App\Support\ManualLeadResultTags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class CorretorDashboardController extends Controller
{
    public function __construct(
        private LeadLoversInitialFailureCatalog $leadLoversFailureCatalog,
    ) {}

    public function index(Request $request)
    {
        $corretor = Auth::guard('admin')->user();

        abort_if(! $corretor, 401, 'Corretor não autenticado.');

        $canViewLeads = Gate::forUser($corretor)->allows('view-leads');

        $canViewRealEstateCompanies = Gate::forUser($corretor)
            ->allows('view-real-estate-companies');

        $canAcessSimulationForms = Gate::forUser($corretor)
            ->allows('access-simulation-forms');

        $canStartInsuranceAnalysis = Gate::forUser($corretor)->allows('create-analysis');

        $leadSearch = $request->input('lead_name', '');

        $selectedImobiliaria = $request->input('imobiliaria', '');

        $requestResultado = mb_strtolower(trim((string) $request->input('resultado', '')));

        $legacyResultadoAliases = [
            'aprovado' => ManualLeadResultTags::APPROVED,
            'recusado' => ManualLeadResultTags::REJECTED,
        ];

        $selectedResultado = $legacyResultadoAliases[$requestResultado] ?? $requestResultado;

        $leadLoversSyncOptions = $this->leadLoversFailureCatalog
            ->dashboardSyncOptions();
        $selectedLeadLoversSync = trim(
            (string) $request->input('leadlovers_sync', '')
        );

        if (! array_key_exists(
            $selectedLeadLoversSync,
            $leadLoversSyncOptions
        )) {
            $selectedLeadLoversSync = '';
        }

        $resultadoOptions = collect(
            ManualLeadResultTags::all()
        );

        if (
            $selectedResultado !== ''
            && ! $resultadoOptions->has($selectedResultado)
        ) {
            $selectedResultado = '';
        }

        $resultTagsTitles = $canViewLeads
            ? LeadLoversTag::query()
                ->whereIn(
                    'key',
                    ManualLeadResultTags::leadloversKeys()
                )->pluck('title', 'key')
            : collect();

        $leadsQuery = Lead::query()
            ->createdThroughSystem()
            ->with([
                'endereco',
                'despesas',
                'conjuge',
                'imobiliariaVinculada',
                'imobiliariaInformada',
                'locador',
                'insuranceAnalyses',
                'leadLoversTagOperation',
            ]);

        $leadsQuery->when($leadSearch, function ($query) use ($leadSearch) {
            $query->where(function ($subQuery) use ($leadSearch) {
                $subQuery->where('nome', 'like', "%$leadSearch%")
                    ->orWhere('email', 'like', "%$leadSearch%")
                    ->orWhere('cpf', 'like', "%$leadSearch%")
                    ->orWhere('tel', 'like', "%$leadSearch%");
            });
        });

        $tipoSolicitantesOptions = [
            'imobiliaria_cadastrada' => 'Imobiliária cadastrada',
            'imobiliaria_nao_cadastrada' => 'Imobiliária não cadastrada',
            'locador' => 'Proprietário / locador',
            'locatario' => 'Locatário',
        ];

        $selectedTipoSolicitante = (string) $request->input(
            'tipo_solicitante',
            ''
        );

        if ($selectedImobiliaria === 'sem_vinculo') {
            $leadsQuery->whereNull('company_id');
        } elseif ($selectedImobiliaria !== '') {
            $leadsQuery->where(
                'company_id',
                (int) $selectedImobiliaria
            );
        }

        if (
            $selectedTipoSolicitante !== ''
            && ! array_key_exists(
                $selectedTipoSolicitante,
                $tipoSolicitantesOptions
            )
        ) {
            $selectedTipoSolicitante = '';
        }

        $leadsQuery->when(
            $selectedTipoSolicitante !== '',
            function ($query) use ($selectedTipoSolicitante) {
                $query->where(
                    'tipo_solicitante',
                    $selectedTipoSolicitante
                );
            }
        );

        if ($selectedResultado !== '') {
            $definition = $resultadoOptions->get($selectedResultado);
            $selectedTagKey = $definition['leadlovers_key'] ?? null;
            $selectedTagTitle = $resultTagsTitles->get($selectedTagKey);

            if (filled($selectedTagTitle)) {
                $escapedTagTitle = addcslashes(
                    (string) $selectedTagTitle,
                    '%_\\'
                );

                $leadsQuery->where(
                    'tags_originais',
                    'like',
                    "%{$escapedTagTitle}%"
                );
            } else {
                $leadsQuery->whereRaw('1 = 0');
            }
        }

        if (
            $selectedLeadLoversSync
            === LeadLoversInitialFailureCatalog::DASHBOARD_FILTER_NOT_SENT
        ) {
            $leadsQuery->notSentToLeadLoversBecauseOfInvalidData();
        }

        $notSentToLeadLoversCount = $canViewLeads
            ? Lead::query()
                ->notSentToLeadLoversBecauseOfInvalidData()
                ->count()
            : 0;

        $leads = $canViewLeads
            ? $leadsQuery
                ->approvedFirst()
                ->latest('created_at')
                ->latest('id')
                ->paginate(6)
                ->withQueryString()
            : collect();

        $leadLoversFailures = $canViewLeads
            ? $leads->getCollection()
                ->mapWithKeys(fn (Lead $lead): array => [
                    (int) $lead->id => $this->leadLoversFailureCatalog->describe($lead),
                ])
                ->all()
            : [];

        $dashboardStats = [
            'totalLeads' => $canViewLeads ? Lead::query()->createdThroughSystem()->count() : 0,
            'newLeads' => $canViewLeads
                ? Lead::query()->createdThroughSystem()->where('status', 'novo')->count()
                : 0,
            'recentLeads' => $canViewLeads
                ? Lead::query()->createdThroughSystem()
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count()
                : 0,

            'totalImobiliarias' => $canViewRealEstateCompanies ? Imobiliaria::count() : 0,

            'totalAprovados' => $canViewLeads
                ? Lead::query()->createdThroughSystem()
                    ->where('tags_originais', 'like', '%aprovad%')
                    ->count()
                : 0,

            'totalRecusados' => $canViewLeads
                ? Lead::query()->createdThroughSystem()->where(function ($query) {
                    $query->where('tags_originais', 'like', '%recusad%')
                        ->orWhere('tags_originais', 'like', '%reprovad%')
                        ->orWhere('tags_originais', 'like', '%ruim%');
                })->count()
                : 0,

            'latestLeadAt' => $canViewLeads
                ? Lead::query()->createdThroughSystem()
                    ->latest('created_at')
                    ->value('created_at')
                : null,

        ];

        $imobiliarias = $canViewLeads
            ? Imobiliaria::query()->orderBy('name')->get()
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
            'selectedTipoSolicitante',
            'tipoSolicitantesOptions',
            'simulationCompanies',
            'canAcessSimulationForms',
            'canStartInsuranceAnalysis',
            'selectedLeadLoversSync',
            'leadLoversSyncOptions',
            'notSentToLeadLoversCount',
            'leadLoversFailures',
        ));

    }
}
