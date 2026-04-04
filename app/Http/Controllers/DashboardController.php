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

            Log::info("INICIANDO SINCRONIZAÇÃO DA EMPRESA: " . $company->name);

        do {

            Log::info("Buscando página: " . $page);

            $response = Http::timeout(120)->get($this->baseUrl . 'Leads?token=' . $token . '&page=' . $page)->json();


            $leadsDaPagina = $response['Data'] ?? [];

            if (empty($leadsDaPagina)) {

                Log::info("Página vazia! Fim da busca.");

                break; 
            }

            //dd("ESTRUTURA DE 1 LEAD:", $leadsDaPagina[5]);

            foreach ($leadsDaPagina as $leadData) {

                $tagsDoLead = $leadData['Company'] ?? '';

                // 2. Transforma em texto puro (string) caso a API mande como Array
                if (is_array($tagsDoLead)) {
                    $tagsDoLead = implode(', ', $tagsDoLead);
                }

                $tagLimpa = Str::slug($tagsDoLead);
                $nomeLimpo = Str::slug($company->name);

                // 3. A MÁGICA DO SELECT: Como o $company->name agora é exato, 
                // o stripos procura ele no meio do texto de tags do lead.
                if (str_contains($tagLimpa, $nomeLimpo)) {

                    $fichaCompleta = Http::timeout(60)->get($this->baseUrl . 'Lead?token=' . $this->token . '&email=' . $leadData['Email'])->json();

                    dd("FICHA COMPLETA DO LEAD:", $fichaCompleta);
                                        
                    Lead::updateOrCreate(
                        ['email' => $leadData['Email']], 
                        [   
                            'nome' => $leadData['Name'] ?? 'Sem Nome',
                            'tel' => $leadData['Phone'] ?? null,
                            'cidade' =>$leadData['City'] ?? null,
                            'company_id' => $company->id,
                            'tags_originais' => $tagsDoLead,
                            'status' => 'novo'
                        ]
                    );
                }
            }

            $page++;

            sleep(1);

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

        return view('dashboard-user', compact('leads'))->with('success', 'Login realizado com SUCESSO!!');
    }

}
