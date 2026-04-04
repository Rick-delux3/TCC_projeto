<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Company;
use App\Models\Lead;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {

        
        Log::info('=== CHEGOU UM NOVO LEAD DO LEADLOVERS ===');
        Log::info($request->all());

        $cpf = $request->input('cpf');
        $cpf_casado = $request->input('cpf_casado');
        $nome = $request->input('nome');
        $email = $request->input('email');
        $telefone = $request->input('tel');
        $cidade = $request->input('cidade');
        $estado = $request->input('estado');
        $tagsString = $request->input('tags', '');
        $valor_aluguel = $request->input('valor_aluguel', null);
        $imobiliaria = $request->input('imobiliaria');

        $textoParaBuscar = $imobiliaria;
         // Ex: "Imobiliaria_ABC, VIP"

        if (!$email) {
            return response()->json(['error' => 'Email vazio'], 400);
        }

        // Transforma a string de tags do LeadLovers num array
        $nomeLimpoLead = Str::slug($textoParaBuscar);
        
        $companies = Company::all();
        $companyEncontrada = null;

        foreach ($companies as $comp) {
            if (str_contains($nomeLimpoLead, Str::slug($comp->name))) {
                $companyEncontrada = $comp;
                break;
            }
        }

        // Se achou a imobiliária, salva o lead para ela
        if ($companyEncontrada) {
            Lead::updateOrCreate(
                ['email' => $email], // Se o email já existir, ele só atualiza os dados
                [
                    'cpf' => $cpf,
                    'cpf_casado' => $cpf_casado,
                    'nome' => $nome,
                    'tel' => $telefone,
                    'cidade' => $cidade,
                    'estado' => $estado,
                    'company_id' => $companyEncontrada->id, // Aqui acontece a mágica do vínculo!
                    'tags_originais' => $tagsString,
                    'imobiliaria' => $imobiliaria,
                    'valor_aluguel' => $valor_aluguel
                ]
            );
        }

        return response()->json(['status' => 'sucesso'], 200);
        
    }
}
