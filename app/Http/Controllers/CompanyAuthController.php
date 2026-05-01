<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\TwoFactorCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class CompanyAuthController extends Controller
{
    public function showLoginForm()
    {
        return view('company-login');
    }

    

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $throttleKey = 'company-login:' . Str::lower($data['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()
                ->withErrors([
                    'email' => 'Muitas tentativas de login. Tente novamente em alguns minutos.',
                ])
                ->onlyInput('email');
        }

        $company = Company::where('email', $data['email'])->first();

        if (!$company || !Hash::check($data['password'], $company->password)) {
            RateLimiter::hit($throttleKey, 60);

            return back()->withErrors(['email' => 'E-mail ou senha incorretos.']);
        }

        $user = User::where('company_id', $company->id)->first();

        if (!$user) {
            return back()->withErrors(['email' => 'Usuario admin nao encontrado.'])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user);
        $request->session()->regenerate();
        
        session(['company_id' => $company->id]);
        // Keep only one active code per user.
        TwoFactorCode::where('user_id', $user->id)->delete();

        $code = random_int(100000, 999999);

        TwoFactorCode::create([
            'user_id' => $user->id,
            'code' => Hash::make((string) $code),
            'expires_at' => now()->addMinutes(10),
        ]);

        try {

            Mail::send('emails.2fa-code', ['code' => $code], function ($message) use ($request) {
                $message->to($request->email)->subject('Seu codigo de verificacao');
            });

        } catch (\Throwable $e) {

             Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withErrors([
                    'email' => 'Não foi possível enviar o código de verificação. Tente novamente.',
                ])
                ->onlyInput('email');
        }


        session()->forget('2fa_passed');

        // Reset old 2FA throttling state for this login challenge.
        RateLimiter::clear('2fa:verify:' . $user->id . ':' . $request->ip());
        RateLimiter::clear('2fa:resend:' . $user->id . ':' . $request->ip());
        RateLimiter::clear('2fa:resend-cooldown:' . $user->id . ':' . $request->ip());

        return redirect()->route('2fa')->with('success', 'Codigo enviado ao seu e-mail.');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();


        return redirect()->route('empresa.login')->with('success', 'Logout realizado com sucesso.');
    }
}
