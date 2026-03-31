<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;




class AnaliseController extends Controller
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
            $token = $this->token;
            $page = $this->page;

            Log::info("INICIANDO SINCRONIZAÇÃO DA EMPRESA: " . $company->name);

        do {

            Log::info("Buscando página: " . $page);
            $response = Http::get($this->baseUrl . 'Leads', [
                'token' => $token, 
                'page' => $page
            ])->json();

            $leadsDaPagina = $response['Leads'] ?? $response;

            if (empty($leadsDaPagina)) {
                Log::info("Página vazia! Fim da busca.");
                break; 
            }

            foreach ($leadsDaPagina as $leadData) {
                $tagsDoLead = $leadData['Tags'] ?? '';
                $tagsArray = is_string($tagsDoLead) ? array_map('trim', explode(',', $tagsDoLead)) : (array) $tagsDoLead;

                if (in_array($company->name, $tagsArray)) {
                    Lead::updateOrCreate(
                        ['email' => $leadData['Email']], 
                        [
                            'name' => $leadData['Name'] ?? 'Sem Nome',
                            'phone' => $leadData['Phone'] ?? null,
                            'company_id' => $company->id,
                            'tags_originais' => is_string($tagsDoLead) ? $tagsDoLead : implode(', ', $tagsArray),
                            'status' => 'novo'
                        ]
                    );
                }
            }

            $page++;

        } while (count($leadsDaPagina) > 0);

        // Marca que a imobiliária já foi sincronizada
        $company->update(['sincronizado_em' => now()]);
        Log::info("Sincronização FINALIZADA com sucesso!");
    }
    
    public function index(){
        // 1. Recupera a imobiliária correta usando a sessão que você criou no Login
        $companyId = session('company_id');
        
        // Se por algum motivo a sessão não existir, redireciona para o login
        if (!$companyId) {
            return redirect()->route('empresa.login');
        }

        $company = Company::find($companyId);

        // 2. A MÁGICA DA CARGA INICIAL (Roda apenas na primeira vez)
        if (is_null($company->sincronizado_em)) {
            $this->buscarLeadsAntigosNaApi($company);
        }

        // 3. Puxa todos os leads dessa imobiliária do banco de dados local
        // Usamos o relacionamento $company->leads() que criamos antes
        $leads = $company->leads()->orderBy('created_at', 'desc')->get();

        return view('DashboardUser', compact('leads'));
    }

}
