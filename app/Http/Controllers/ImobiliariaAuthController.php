<?php

namespace App\Http\Controllers;

use App\Actions\Companies\StartCompanyTwoFactorChallenge;
use App\Http\Requests\Auth\CompanyLoginRequest;
use App\Models\Imobiliaria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ImobiliariaAuthController extends Controller
{
    public function __construct(
        private StartCompanyTwoFactorChallenge $startChallenge
    ) {}

    public function showLoginForm(): View
    {
        return view('imobiliaria.company-login');
    }

    public function login(CompanyLoginRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $throttleKey = 'company-login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return redirect()->route('empresa.login')
                ->withErrors([
                    'email' => 'Muitas tentativas de login. Tente novamente em alguns minutos.',
                ])
                ->onlyInput('email');
        }

        $company = Imobiliaria::query()->where('email', $data['email'])->first();

        if (! $company || ! Hash::check($data['password'], $company->password)) {
            RateLimiter::hit($throttleKey, 60);

            return redirect()->route('empresa.login')
                ->withErrors(['email' => 'E-mail ou senha incorretos.'])->onlyInput('email');
        }

        $user = $company->users()->orderBy('id')->first();

        if (! $user) {
            return redirect()->route('empresa.login')
                ->withErrors(['email' => 'Não foi possível acessar esta imobiliária. Entre em contato com o suporte.'])
                ->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);

        $user->setRelation('company', $company);

        if (! $this->startChallenge->execute($user, $request)) {
            return redirect()->route('empresa.login')
                ->withErrors([
                    'email' => 'Não foi possível enviar o código de verificação. Tente novamente.',
                ])
                ->onlyInput('email');
        }

        return redirect()->route('2fa')->with('success', 'Codigo enviado ao seu e-mail.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('empresa.login')->with('success', 'Logout realizado com sucesso.');
    }
}
