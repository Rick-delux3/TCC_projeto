<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\InsuranceAnalysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;


class CorretorDashboardController extends Controller
{
    public function index(Request $request)
    {
        $corretor = Auth::guard('admin')->user();

        $leadSearch = $request->input('lead_name', '');

        $selectedImobiliaria = $request->input('imobiliaria', '');

        $selectedResultado = $request->input('resultado', '');

        $leadsQuery = Lead::query()
            ->with([
                'endereco',
                'despesas',
                'imobiliaria',
                'insuranceAnalysis',
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

        $leads = $leadsQuery->paginate(6)->withQueryString();

        $dashboardStats = [
            'totalLeads' => Lead::count(),
            'newLeads' => Lead::where('status', 'novo')->count(),
            'recentLeads' => Lead::where('created_at', '>=', now()->subDays(7))->count(),

            'totalImobiliarias' => Imobiliaria::count(),

            
            'totalAprovados' => Lead::where('tags_originais', 'like', '%aprovad%')->count(),

            'totalRecusados' => Lead::where(function ($query) {
                $query->where('tags_originais', 'like', '%recusad%')
                    ->orWhere('tags_originais', 'like', '%reprovad%')
                    ->orWhere('tags_originais', 'like', '%ruim%');
            })->count(),


            'latestLeadAt' => Lead::latest('created_at')->value('created_at'),


        ];

        $imobiliarias = Imobiliaria::query()
            ->orderBy('name')
            ->get();

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
