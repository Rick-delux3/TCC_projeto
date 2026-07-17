<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\InsuranceAnalysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;


class CorretorDashboardController extends Controller
{
    public function index(Request $request)
    {
        $corretor = Auth::guard('admin')->user();

        abort_if(! $corretor, 401, 'Corretor não autenticado.');

        $canViewLeads = Gate::forUser($corretor)->allows('view-leads');
        $canViewRealEstateCompanies = Gate::forUser($corretor)
            ->allows('view-real-estate-companies');
        $canCreateAnalysis = Gate::forUser($corretor)->allows('create-analysis');

        $leadSearch = $request->input('lead_name', '');

        $selectedImobiliaria = $request->input('imobiliaria', '');

        $selectedResultado = $request->input('resultado', '');

        $leadsQuery = Lead::query()
            ->with([
                'endereco',
                'despesas',
                'conjuge',
                'imobiliariaVinculada',
                'imobiliariaInformada',
                'locador',
                'insuranceAnalyses',
            ])->latest();
        
        $leadsQuery->when($leadSearch, function ($query) use ($leadSearch){
            $query->where(function ($subQuery) use ($leadSearch){
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

    
         if($selectedImobiliaria === 'sem_vinculo') {
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

        $leadsQuery->when($selectedResultado, function ($query) use ($selectedResultado) {
            $query->where(function ($subQuery) use ($selectedResultado) {
                if ($selectedResultado === 'aprovado') {
                    $subQuery->where('tags_originais', 'like', '%aprovad%');
                }

                if ($selectedResultado === 'recusado') {
                $subQuery->where('tags_originais', 'like', '%recusad%')
                    ->orWhere('tags_originais', 'like', '%reprovad%')
                    ->orWhere('tags_originais', 'like', '%ruim%');
                }
            });
        });

        $leads = $canViewLeads
            ? $leadsQuery->paginate(6)->withQueryString()
            : collect();

        $dashboardStats = [
            'totalLeads' => $canViewLeads ? Lead::count() : 0,
            'newLeads' => $canViewLeads ? Lead::where('status', 'novo')->count() : 0,
            'recentLeads' => $canViewLeads
                ? Lead::where('created_at', '>=', now()->subDays(7))->count()
                : 0,

            'totalImobiliarias' => $canViewRealEstateCompanies ? Imobiliaria::count() : 0,

            
            'totalAprovados' => $canViewLeads
                ? Lead::where('tags_originais', 'like', '%aprovad%')->count()
                : 0,

            'totalRecusados' => $canViewLeads
                ? Lead::where(function ($query) {
                    $query->where('tags_originais', 'like', '%recusad%')
                        ->orWhere('tags_originais', 'like', '%reprovad%')
                        ->orWhere('tags_originais', 'like', '%ruim%');
                })->count()
                : 0,


            'latestLeadAt' => $canViewLeads
                ? Lead::latest('created_at')->value('created_at')
                : null,


        ];

        $imobiliarias = $canViewLeads
            ? Imobiliaria::query()->orderBy('name')->get()
            : collect();

        $simulationCompanies = $canCreateAnalysis
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
            'selectedTipoSolicitante',
            'tipoSolicitantesOptions',
            'simulationCompanies',
            'canCreateAnalysis',
        ));
        
    }
}
