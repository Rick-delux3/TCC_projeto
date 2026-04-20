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

        //$cpf = $request->input('cpf');
        //$cpf = empty($cpf) ? null : $cpf;

        //$cpf_casado = $request->input('cpf_casado');
        //$cpf_casado = empty($cpf_casado) ? null : $cpf_casado;


        $nome = $request->input('Nome');
        $email = $request->input('Email');
        $telefone = $request->input('Telefone');
        $cidade = $request->input('Cidade');
        $estado = $request->input('Estado');
        $tagsString = $request->input('Tags', '');
        $imobiliaria = $request->input('Empresa');
        
        if (is_array($imobiliaria)) { $imobiliaria = implode(', ', $imobiliaria); }
        if (is_array($tagsString)) { $tagsString = implode(', ', $tagsString); }
        if (is_array($cidade)) { $cidade = implode(', ', $cidade); }
        
        $textoParaBuscar = $imobiliaria;
        

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
                    //'cpf' => $cpf,
                    //'cpf_casado' => $cpf_casado,
                    'nome' => $nome,
                    'tel' => $telefone,
                    'cidade' => $cidade,
                    'estado' => $estado,
                    'company_id' => $companyEncontrada->id, // Aqui acontece a mágica do vínculo!
                    'tags_originais' => $tagsString,
                    'imobiliaria' => $imobiliaria
                ]
            );
            Log::info("SUCESSO: Lead vinculado à imobiliária: " . $companyEncontrada->name);
        }
        else {
            Log::warning("ALERTA: Lead de email {$email} não foi salvo! A imobiliária '{$imobiliaria}' não foi encontrada no banco de dados.");
        }
        return response()->json(['status' => 'sucesso'], 200);
        
    }
}
