<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request; 
use App\Models\Lead;
use App\Models\Company;
use App\Http\Requests\StorePublicLeadRequest;
use App\Jobs\SendLeadToLeadLoversJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\LeadLoversService;
class PublicLeadController extends Controller
{
    private $token;
    private $baseUrl;
    private $machine;
    private $sequence;
    private $step;

    public function __construct(){
        $this->token = config('services.leadlovers.token');
        $this->baseUrl = 'https://llapi.leadlovers.com/webapi/';
        $this->machine = config('services.leadlovers.machine');
        $this->sequence = config('services.leadlovers.sequence');
        $this->step = config('services.leadlovers.step');

    }

    public function show(string $token){

        $company = Company::where('lead_form_token', $token)
        ->where('lead_form_active', true)
        ->firstOrFail();



        return view('create-lead-form', compact('company'));
    }

    
    public function store(StorePublicLeadRequest $request, string $token, SendLeadToLeadLoversJob $leadLovers){
        
        $company = Company::where('lead_form_token', $token)
        ->where('lead_form_active', true)
        ->firstOrFail();

        $data = $request->validated();


        $valorAluguel =  (float) $data['valor_aluguel'];
        $outrasDespesas = (float) $data['outras_despesas'];

        $Lead = Lead::updateOrCreate(
            [
                'company_id' => $company->id,
                'email' => $data['email'],
            ],

            [
                'nome' => $data['nome'],
                'cpf' => $data['cpf'],
                'tel' => $data['telefone'],
                'estado_civil' => $data['estado_civil'],
                'conjuge_nome' => $data['conjuge_nome'] ?? null,
                'conjuge_cpf' => $data['conjuge_cpf'] ?? null,
                'valor_aluguel' => $valorAluguel,
                'outras_despesas' => $outrasDespesas,
                'valor_total_encargos' => $valorAluguel + $outrasDespesas,
                'cidade_imovel' => $data['cidade_imovel'],
                'responsavel_preenchimento' => $data['responsavel_preenchimento'],
                'imobiliaria' => $company->name,
                'tags_originais' => $company->name,
                'status' => 'novo',
                'leadlovers_status' => 'pending',
                'origem' => 'formulario_publico',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        
        );

       $leadLovers::dispatch($Lead->id);


    }
            
            
}
