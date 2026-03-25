<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Company;
use App\Models\Lead;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {

        
        Log::info('=== CHEGOU UM NOVO LEAD DO LEADLOVERS ===');
        Log::info($request->all());


        $email = $request->input('email');
        $nome = $request->input('name');
        $telefone = $request->input('phone');
        $tagsString = $request->input('tags', ''); // Ex: "Imobiliaria_ABC, VIP"

        if (!$email) {
            return response()->json(['error' => 'Email vazio'], 400);
        }

        // Transforma a string de tags do LeadLovers num array
        $tagsArray = array_map('trim', explode(',', $tagsString));

        // Busca qual imobiliária (Company) tem o 'name' dentro das tags que chegaram
        $company = Company::whereIn('name', $tagsArray)->first();

        // Se achou a imobiliária, salva o lead para ela
        if ($company) {
            Lead::updateOrCreate(
                ['email' => $email], // Se o email já existir, ele só atualiza os dados
                [
                    'name' => $nome,
                    'phone' => $telefone,
                    'company_id' => $company->id, // Aqui acontece a mágica do vínculo!
                    'tags_originais' => $tagsString,
                ]
            );
        }

        return response()->json(['status' => 'sucesso'], 200);
        
    }
}
