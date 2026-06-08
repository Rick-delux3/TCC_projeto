<?php

namespace App\Http\Controllers;

use App\Models\Imobiliaria;
use App\Models\LeadLoversTag;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Http\Requests\StoreCompanyRequest;

class ImobiliariaRegistrationController extends Controller
{
    public function showRegistrationForm()
    {
       $blockedTerms = [
            'morna',
            'sem negocios',
            'sem negócios',
            'ativas',
            'ativa',
            'ativo',
            'status',
            'aprovado',
            'recusado',
            'reprovado',
            'ruim',
            'negociação',
            'negociacao',
            'carro',
            'atraso',

        ];

        $tagsQuery = LeadLoversTag::query()
            ->where('active', true)
            ->where(function ($query) {
                $query->where('title', 'like', 'Imobiliária %')
                    ->orWhere('title', 'like', 'Imobiliaria %');
            });

        foreach ($blockedTerms as $term) {
            $tagsQuery->where('title', 'not like', '%' . $term . '%');
        }

        $tagsOficiais = $tagsQuery
            ->orderBy('title')
            ->get([
                'leadlovers_tag_id',
                'title',
            ]);

        return view('register-company', compact('tagsOficiais'));
        // Ajuste o nome da view acima para o nome real do seu arquivo blade
    }

    public function store(StoreCompanyRequest $request)
    {
        $data = $request->validated();

        $companyTag = LeadLoversTag::where('leadlovers_tag_id', $data['leadlovers_tag_id'])
            ->where('active', true)
            ->firstOrFail();

        $company = Imobiliaria::create([
            'name' => $companyTag->title,
            'email' => $data['email'],
            'phone' => $data['phone'],
            'cnpj' => $data['cnpj'],
            'city' => $data['city'],
            'state' => $data['state'],
            'password' => Hash::make($data['password']),


            'lead_form_token' => Str::random(64),
            'lead_form_active' => true,

            
            'leadlovers_tag_id' => $companyTag?->leadlovers_tag_id,
            'leadlovers_tag_name' => $companyTag?->title,
        ]);

        $user = User::create([
            'name' => $companyTag->title,
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'company_id' => $company->id,
        ]);

        // Sends the standard Laravel email verification link.
        event(new Registered($user));


        return redirect()->route('empresa.login')->with(
            'success',
            'Cadastro realizado com sucesso. Verifique seu e-mail antes de concluir o acesso.'
        );
    }
}
