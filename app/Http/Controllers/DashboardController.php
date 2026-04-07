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

            Log::info("INICIANDO SINCRONIZAÃ‡ÃƒO DA EMPRESA: " . $company->name);

        do {

            Log::info("Buscando pÃ¡gina: " . $page);

            $response = Http::timeout(120)->get($this->baseUrl . 'Leads?token=' . $token . '&page=' . $page)->json();


            $leadsDaPagina = $response['Data'] ?? [];

            if (empty($leadsDaPagina)) {

                Log::info("PÃ¡gina vazia! Fim da busca.");

                break; 
            }

            //dd("ESTRUTURA DE 1 LEAD:", $leadsDaPagina[5]);

            foreach ($leadsDaPagina as $leadData) {



                $empresa = $leadData['Company'] ?? '';

                // 2. Transforma em texto puro (string) caso a API mande como Array
                if (is_array($empresa)) {
                    $empresa = implode(', ', $empresa);
                }

                $tagLimpa = Str::slug($empresa);
                $nomeLimpo = Str::slug($company->name);

                // 3. A MÃGICA DO SELECT: Como o $company->name agora Ã© exato,
                // o stripos procura ele no meio do texto de tags do lead.
                if (str_contains($tagLimpa, $nomeLimpo)) {

                    $fichaCompleta = Http::timeout(60)->get($this->baseUrl . 'Lead?token=' . $token . '&email=' . $leadData['Email'])->json();

                    $statusDaAnalise = $fichaCompleta['Tags'] ?? '';
                    
                    // Prevenção caso o Leadlovers mande como Array em vez de texto
                    if (is_array($statusDaAnalise)) {
                        $statusDaAnalise = implode(', ', $statusDaAnalise);
                    }
                                        
                    Lead::updateOrCreate(
                        ['email' => $leadData['Email']], 
                        [   
                            'nome' => $leadData['Name'] ?? 'Sem Nome',
                            'tel' => $leadData['Phone'] ?? null,
                            'cidade' => $leadData['City'] ?? null,
                            'company_id' => $company->id,
                            'imobiliaria' => $empresa,
                            'tags_originais' => '',
                            'status' => !empty($statusDaAnalise) ? $statusDaAnalise : 'novo'
                        ]
                    );
                }
            }

            $page++;

            sleep(1);

        } while (count($leadsDaPagina) > 0);

        // Marca que a imobiliÃ¡ria jÃ¡ foi sincronizada
        $company->update(['sincronizado_em' => now()]);

        Log::info("SincronizaÃ§Ã£o FINALIZADA com sucesso!");
    }
    
    public function index(){
        // 1. Recupera a imobiliÃ¡ria correta usando a sessÃ£o que vocÃª criou no Login
        $companyId = session('company_id');
        
        // Se por algum motivo a sessÃ£o nÃ£o existir, redireciona para o login
        if (!$companyId) {
            return redirect()->route('empresa.login');
        }

        $company = Company::find($companyId);

        // 2. A MÃGICA DA CARGA INICIAL (Roda apenas na primeira vez)
        if (is_null($company->sincronizado_em)) {
            $this->buscarLeadsAntigosNaApi($company);
        }

        $recentThreshold = now()->subDays(7);

        // 3. Puxa os leads em pÃ¡ginas para manter o painel mais leve
        $leads = $company->leads()
            ->orderBy('created_at', 'desc')
            ->paginate(8)
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
        $topTags = $company->leads()
            ->pluck('tags_originais')
            ->filter()
            ->flatMap(function ($tags) {
                return collect(preg_split('/\s*,\s*/', $tags))
                    ->filter(fn ($tag) => filled($tag))
                    ->map(fn ($tag) => trim($tag));
            })
            ->countBy()
            ->sortDesc()
            ->take(4);

        $dashboardStats = [
            'totalLeads' => $totalLeads,
            'newLeads' => $newLeads,
            'recentLeads' => $recentLeads,
            'withPhone' => $withPhone,
            'withoutPhone' => max($totalLeads - $withPhone, 0),
            'latestLeadAt' => optional($latestLead)->created_at,
        ];

        return view('dashboard-user', compact('leads', 'dashboardStats', 'topTags'))
            ->with('success', 'Login realizado com SUCESSO!!');
    }
}
