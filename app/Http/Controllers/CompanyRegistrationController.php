<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\LeadLoversService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CompanyRegistrationController extends Controller
{
    public function showRegistrationForm()
    {
        return view('register-company');
    }

    public function store(Request $request, LeadLoversService $leadLovers)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:companies,name',
            'email' => 'required|string|lowercase|email:rfc,dns|max:255|unique:companies,email|unique:users,email',
            'phone' => 'required|string|max:15|unique:companies,phone',
            'city' => 'required|string|max:55',
            'state' => 'required|string|max:2',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $company = Company::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'city' => $data['city'],
            'state' => $data['state'],
            'password' => Hash::make($data['password']),
        ]);

        $user = User::create([
            'name' => $data['name'] . ' - Admin',
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'company_id' => $company->id,
        ]);

        // Sends the standard Laravel email verification link.
        event(new Registered($user));

        $res = $leadLovers->createLead([
            'Name' => $company->name,
            'Email' => $company->email,
            'Phone' => $company->phone ?? '',
            'City' => $company->city,
            'State' => $company->state,
        ]);

        if (!is_array($res) || !isset($res['StatusCode']) || $res['StatusCode'] !== 200) {
            return back()->withErrors([
                'leadlovers' => 'Erro ao criar o lead no LeadLovers. Detalhes: ' . json_encode($res),
            ]);
        }

        return redirect()->route('empresa.login')->with(
            'success',
            'Cadastro realizado com sucesso. Verifique seu e-mail antes de concluir o acesso.'
        );
    }
}
