<?php

namespace App\Http\Controllers;

use App\Models\Corretor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CorretorRegistrationController extends Controller
{
    public function showCeoRegistrationForm()
    {
        return view('corretor.admin-ceo-register');
    }

    public function storeCeo(Request $request){
        $request->merge([
            'name' => trim(preg_replace('/\s+/', ' ', (string) $request->name)),
            'email' => mb_strtolower(trim((string) $request->email)),
            'cpf' => preg_replace('/\D/', '', (string) $request->cpf),
        ]);

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:corretores,name',
            'email' => 'required|string|lowercase|email:rfc|max:255|unique:corretores,email',
            'password' => 'required|string|min:6|confirmed',
            'cpf' => 'required|string|regex:/^\d{11}$/|unique:corretores,cpf',

        ],
            [
                'name.required' => 'Informe o nome do CEO.',
                
                'email.required' => 'O campo e-mail é obrigatório.',
                'email.email' => 'O campo e-mail deve ser um endereço de e-mail válido.',
                'email.unique' => 'O e-mail informado já está em uso.',

                'password.required' => 'O campo senha é obrigatório.',
                'password.confirmed' => 'A confirmação da senha não corresponde.',

                'cpf.required' => 'O campo CPF é obrigatório.',
                'cpf.regex' => 'O campo CPF deve conter exatamente 11 dígitos numéricos.',
                'cpf.unique' => 'O CPF informado já está em uso.',
            ]
        );

        if (Corretor::where('role', Corretor::ROLE_CEO)->exists()) {
            abort(403, 'O CEO já foi cadastrado. O cadastro inicial está fechado.');
        }

        Corretor::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'cpf' => $data['cpf'],
            'role' => Corretor::ROLE_CEO,
            'active' => true,
            'first_login_verified_at' => null,
            'first_login_code_sent_at' => null,
            'last_login_at' => null,
        ]);

        return redirect()->route('admin.ceo.login')->with(
            'success',
            'Cadastro realizado!! Faça login para continuar!!'
        );

    }

}
