<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Jobs\SyncCompanyLeadLoversLeadsJob;

class DashboardController extends Controller
{


    public function syncStatus(Request $request)
    {
        $companyId = session('company_id');

        if (!$companyId) {
            return response()->json([
                'authenticated' => false,
                'message' => 'Usuário não autenticado.',
            ], 401);
        }

        $company = Company::find($companyId);

        if (!$company) {
            return response()->json([
                'authenticated' => false,
                'message' => 'Empresa não encontrada.',
            ], 404);
        }

        return response()->json([
            'authenticated' => true,
            'sync_status' => $company->sync_status,
            'sync_error' => $company->sync_error,
            'sincronizado_em' => optional($company->sincronizado_em)->format('d/m/Y H:i'),
            'total_leads' => $company->leads()->count(),
        ]);
    }
    
    public function index(Request $request){
        $companyId = session('company_id');
        
        if (!$companyId) {
            return redirect()->route('empresa.login');
        }

        $company = Company::find($companyId);

        if (is_null($company->sincronizado_em) &&
            !in_array($company->sync_status, ['queued', 'running'])
            ) 
        {
             $company->update([
                'sync_status' => 'queued',
                'sync_error' => null,
            ]);

            SyncCompanyLeadLoversLeadsJob::dispatch($company->id);
            
        }

        $recentThreshold = now()->subDays(7);
        $selectedTag = trim((string) $request->query('tag', ''));

        $tagCounts = $company->leads()
            ->pluck('tags_originais')
            ->filter()
            ->flatMap(function ($tags) {
                return collect(preg_split('/\s*,\s*/', $tags))
                    ->filter(fn ($tag) => filled($tag))
                    ->map(fn ($tag) => trim($tag));
            })
            ->countBy()
            ->sortDesc();

        $leadsQuery = $company->leads()->orderBy('created_at', 'desc');

        if (filled($selectedTag)) {
            $leadsQuery->where('tags_originais', 'like', '%' . addcslashes($selectedTag, '%_\\') . '%');
        }

        $leads = $leadsQuery
            ->paginate(6)
            ->withQueryString();

        $totalLeads = $company->leads()->count();
        $newLeads = $company->leads()
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', 'novo');
            })
            ->count();
        $recentLeads = $company->leads()
            ->where('created_at', '>=', $recentThreshold)
            ->count();

        $withPhone = $company->leads()
            ->whereNotNull('tel')
            ->where('tel', '!=', '')
            ->count();

        $latestLead = $company->leads()
            ->latest('created_at')
            ->first();

        $topTags = $tagCounts->take(4);
        $filterTags = $tagCounts;

        $dashboardStats = [
            'totalLeads' => $totalLeads,
            'newLeads' => $newLeads,
            'recentLeads' => $recentLeads,
            'withPhone' => $withPhone,
            'withoutPhone' => max($totalLeads - $withPhone, 0),
            'latestLeadAt' => optional($latestLead)->created_at,
            'filteredLeads' => $leads->total(),
        ];

         return view('dashboard-user', [
            'leads' => $leads,
            'dashboardStats' => $dashboardStats,
            'topTags' => $topTags,
            'filterTags' => $filterTags,
            'selectedTag' => $selectedTag,
            'syncStatus' => $company->sync_status,
            'syncError' => $company->sync_error,
        ]);
    }
}
