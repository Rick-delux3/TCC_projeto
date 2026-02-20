<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Company;
use Illuminate\Support\Facades\Hash;

class CompanyAuthController extends Controller
{
    public function showLoginForm()
    {
        return view('company-login');
    }

    public function login(Request $request)
    {
        // ✅ Validação
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        // ✅ Busca a empresa pelo e-mail
        $company = Company::where('email', $data['email'])->first();

        // ✅ Verifica se existe e se a password está correta
        if (!$company || !Hash::check($data['password'], $company->password)) {
            return back()->withErrors(['email' => 'E-mail ou senha incorretos.']);
        }

        // ✅ Salva login na sessão
        session(['company_id' => $company->id]);

        // ✅ Redireciona
        return redirect('analise')->with('success', 'Login realizado com sucesso!');
    }

    public function logout()
    {
        session()->forget('company_id');
        return redirect()->route('empresa.login')->with('success', 'Logout realizado com sucesso!');
    }
}
