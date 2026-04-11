<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    private $page;
    private $token;
    private $baseUrl;

    public function __construct(){
        $this->token = config('services.leadlovers.token');
        $this->page = 1;
        $this->baseUrl = 'https://llapi.leadlovers.com/webapi/';
    }

    private function buscarLeadsAntigosNaApi($company){

            set_time_limit(0);

            $token = $this->token;
            $page = $this->page;

            Log::info("INICIANDO SINCRONIZAÃƒâ€¡ÃƒÆ’O DA EMPRESA: " . $company->name);

        do {

            Log::info("Buscando pÃƒÂ¡gina: " . $page);

            $response = Http::timeout(120)->get($this->baseUrl . 'Leads?token=' . $token . '&page=' . $page)->json();


            $leadsDaPagina = $response['Data'] ?? [];

            if (empty($leadsDaPagina)) {

                Log::info("PÃƒÂ¡gina vazia! Fim da busca.");

                break; 
            }

          

            foreach ($leadsDaPagina as $leadData) {

                $empresa = $leadData['Company'] ?? '';

                
                if (is_array($empresa)) {
                    $empresa = implode(', ', $empresa);
                }

                $tagLimpa = Str::slug($empresa);
                $nomeLimpo = Str::slug($company->name);

               
                if (str_contains($tagLimpa, $nomeLimpo)) {

                    $fichaCompleta = Http::timeout(60)->get($this->baseUrl . 'Lead?token=' . $token . '&email=' . $leadData['Email'])->json();

                    $Tags = $fichaCompleta['Tags'] ?? [];
                    $statusDaAnalise = '';
                    
                    
                    if (is_array($Tags) && isset($Tags[0])) {
                        $statusDaAnalise = $Tags[0]['Title'] ?? '';
                    }

                    if (is_string($Tags)) {
                        $statusDaAnalise = $Tags;
                    }
                                        
                    Lead::updateOrCreate(
                        ['email' => $leadData['Email']], 
                        [   
                            'nome' => $leadData['Name'] ?? 'Sem Nome',
                            'tel' => $leadData['Phone'] ?? null,
                            'cidade' => $leadData['City'] ?? null,
                            'company_id' => $company->id,
                            'imobiliaria' => $empresa,
                            'tags_originais' => $statusDaAnalise,
                            'status' => !empty($statusDaAnalise) ? $statusDaAnalise : 'novo'
                        ]
                    );
                }
            }

            $page++;

            sleep(1);

        } while (count($leadsDaPagina) > 0);

        $company->update(['sincronizado_em' => now()]);

        Log::info("SincronizaÃƒÂ§ÃƒÂ£o FINALIZADA com sucesso!");
    }
    
    public function index(Request $request){
        $companyId = session('company_id');
        
        if (!$companyId) {
            return redirect()->route('empresa.login');
        }

        $company = Company::find($companyId);

        if (is_null($company->sincronizado_em)) {
            $this->buscarLeadsAntigosNaApi($company);
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

        return view('dashboard-user', compact('leads', 'dashboardStats', 'topTags', 'filterTags', 'selectedTag'))
            ->with('success', 'Login realizado com SUCESSO!!');
    }
}
