<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Services\LeadLoversService;



class CompanyRegistrationController extends Controller {

    public function showRegistrationForm()
    {
        return view('register-company');
    }

    public function store(Request $request, LeadLoversService $leadLovers)
    {
        // cria a imobiliária
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:companies,name',
            'email' => 'required|email|unique:companies,email',
            'phone' => 'required|string|max:12|unique:companies,phone',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $company = Company::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => Hash::make($data['password']),
         ]);

        $user = User::create([
            'name' => $data['name'] . ' - Admin',
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'company_id' => $company->id
        ]);

        $res = $leadLovers->createLead([
        "Name" => $company->name,
        "Email" => $company->email,
        "Phone" => $company->phone ?? ""
        ]);

     // PEGAR ID DO LEAD NO LEADLOVERS
        if(isset($res['Lead']['Id'])) {
            $leadId = $res['Lead']['Id'];

            // Adicionar à máquina/funil desejado
            $leadLovers->addLeadToMachine(
                $leadId,
                123456, // machineId
                78910,  // funnelId
                1       // sequence (primeiro email)
            );

            // loga automaticamente
            auth()->login($user);

            // redireciona ao dashboard
            return redirect('analise')->with('success', 'Cadastro realizado com sucesso!');
        }

    }
}