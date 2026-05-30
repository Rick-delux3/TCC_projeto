<?php

namespace App\Http\Controllers;

use App\Models\Corretor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CorretorRegistrationController extends Controller
{
    public function showRegistrationForm()
    {
        return view('admin-register');
    }

    public function store(Request $request){
        $request->merge([
            'name' => trim(preg_replace('/\s+/', ' ', (string) $request->name)),
            'email' => mb_strtolower(trim((string) $request->email)),
            'cpf' => preg_replace('/\D/', '', (string) $request->cpf),
        ]);

        $data = $request->validate([
            'name' => 'required|string|max:255|unique:corretores,name',
            'email' => 'required|string|lowercase|email:rfc,dns|max:255|unique:corretores,email',
            'password' => 'required|string|min:6|confirmed',
            'cpf' => 'required|string|regex:/^\d{11}$/|unique:corretores,cpf',

        ]);

        $corretor = Corretor::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'cpf' => $data['cpf'],
        ]);

        return redirect()->route('admin.login')->with(
            'success',
            'Cadastro realizado!! Faça login para continuar!!'
        );

    }

}
