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

        $leadSearch = $request->input('lead_name', '');

        $selectedImobiliaria = $request->input('imobiliaria', '');

        $selectedResultado = $request->input('resultado', '');

        $leadsQuery = Lead::query()
            ->with([
                'endereco',
                'despesas',
                'conjuge',
                'imobiliaria',
                'insuranceAnalyses',
            ])->latest();
        
        $leadsQuery->when($leadSearch, function ($query) use ($leadSearch){
            $query->where(function ($subQuery) use ($leadSearch){
                $subQuery->where('nome', 'like', "%$leadSearch%")
                    ->orWhere('email', 'like', "%$leadSearch%")
                    ->orWhere('cpf', 'like', "%$leadSearch%")
                    ->orWhere('telefone', 'like', "%$leadSearch%");
            });
        });


         $leadsQuery->when($selectedImobiliaria, function ($query) use ($selectedImobiliaria) {
            $query->where('company_id', $selectedImobiliaria);
        });

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

        return view('corretor.dashboard-admin', compact(
            'corretor',
            'dashboardStats',
            'leads',
            'imobiliarias',
            'leadSearch',
            'selectedImobiliaria',
            'selectedResultado',
        ));
        
    }
}
