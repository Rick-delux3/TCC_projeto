<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request; 
use App\Models\Lead;
use App\Models\Company;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function show(){
        return view('create-lead-form');
    }


    public function store(Request $request){

        
        $data = $request->validate([
            'nome' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email:rfc,dns|max:255',
            'cpf' => 'required|string|regex:/^\d{11}$/',
            'tel' => 'required|string|max:15',
            'cidade' => 'required|string|max:55',
            'cpf_casado' => 'nullable|string|min:11|max:14',
            // Aceita string primeiro porque o usuário pode digitar "R$ 1.500,00"
            'valor_aluguel' => 'required|string|max:20', 
            'outras_despesas' => 'nullable|string|max:20',
            'imobiliaria_nome' => 'required|string|exists:companies,name', 
            'nome_responsavel' => 'required|string|max:255',
            ],
            
            [
                'nome.required'             => 'O nome do cliente é obrigatório.',
                'email.email'               => 'Por favor, digite um e-mail válido.',
                'cpf.required'              => 'O CPF é obrigatório.',
                'tel.required'         => 'O telefone de contato é obrigatório.',
                'imobiliaria_nome.exists'   => 'Imobiliária não encontrada. Por favor, selecione uma imobiliária válida na lista ou cadastre a sua.',
                'imobiliaria_nome.required' => 'Você precisa selecionar a sua imobiliária.',
            'valor_aluguel.required'    => 'O valor do aluguel pretendido é obrigatório.'
            ]);

            $company = Company::where('name', $data['imobiliaria_nome'])->first();

            $valorAluguelLimpo = preg_replace('/[^0-9]/', '', $data['valor_aluguel']) / 100;
            $outrasDespesasLimpo = $data['outras_despesas'] ? preg_replace('/[^0-9]/', '', $data['outras_despesas']) / 100 : null;
    
            $lead = Lead::updateOrCreate(
                ['email' => $data['email']],
                [
                    'nome' => $data['nome'],
                    'cpf' => $data['cpf'],
                    'tel' => $data['tel'],
                    'cidade' => $data['cidade'],
                    'cpf_casado' => $data['cpf_casado'],
                    'valor_aluguel' => $valorAluguelLimpo,
                    'outras_despesas' => $outrasDespesasLimpo,
                    'company_id' => $company->id,
                    'imobiliaria' => $company->name,
                    'nome_responsavel' => $data['nome_responsavel']
                ]
            );

            $this->enviarParaLeadLovers($lead);


            return back()->with('success', 'Lead cadastrado com sucesso! O cliente já entrou no fluxo de atendimento.');

    }

    public function enviarParaLeadLovers($lead) {

       try {
            $response = Http::post($this->baseUrl . 'Lead?token=' . $this->token, [
                'Email'             => $lead->email,
                'MachineCode'       => $this->machine,
                'EmailSequenceCode' => $this->sequence,
                'SequenceLevelCode' => $this->step,
                'Name'              => $lead->nome,
                'Phone'             => $lead->tel,
                'City'              => $lead->cidade,
                'Company'           => $lead->imobiliaria,
            ]);

            if ($response->successful()) {
                Log::info("Sucesso: Lead {$lead->email} integrado na máquina {$this->machine}.");
            } else {
                Log::warning("Aviso API LeadLovers: O lead {$lead->email} não foi integrado. Motivo: " . $response->body());
            }

            return $response->successful();

        } catch (\Exception $e) {
            Log::error("Falha grave na comunicação com a API do LeadLovers: " . $e->getMessage());
            return false;
        }
    }
            
            
}
