<?php

namespace App\Http\Controllers;

use App\Models\Imobiliaria;
use App\Models\TwoFactorCode;
use App\Models\User;
use App\Services\CompanyTwoFactorMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ImobiliariaAuthController extends Controller
{
    public function __construct(
        private CompanyTwoFactorMailService $twoFactorMail
    ) {}

    public function showLoginForm()
    {
        return view('imobiliaria.company-login');
    }

    public function login(Request $request)
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->email)),
        ]);

        $data = $request->validate([
            'email' => 'required|email|max:255',
            'password' => 'required|string|max:72',
        ]);

        $throttleKey = 'company-login:'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return back()
                ->withErrors([
                    'email' => 'Muitas tentativas de login. Tente novamente em alguns minutos.',
                ])
                ->onlyInput('email');
        }

        $company = Imobiliaria::where('email', $data['email'])->first();

        if (! $company || ! Hash::check($data['password'], $company->password)) {
            RateLimiter::hit($throttleKey, 60);

            return back()->withErrors(['email' => 'E-mail ou senha incorretos.']);
        }

        $user = User::where('company_id', $company->id)->first();

        if (! $user) {
            return back()->withErrors(['email' => 'Usuario admin nao encontrado.'])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user);
        $request->session()->regenerate();

        session(['company_id' => $company->id]);
        // Keep only one active code per user.
        TwoFactorCode::where('user_id', $user->id)->delete();

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);

        TwoFactorCode::create([
            'user_id' => $user->id,
            'code' => Hash::make($code),
            'expires_at' => $expiresAt,
        ]);

        try {
            $this->twoFactorMail->sendCode($company->email, $code, $expiresAt);
        } catch (\Throwable $e) {
            TwoFactorCode::where('user_id', $user->id)->delete();

            Log::error('Falha ao enviar código de 2FA da imobiliária.', [
                'exception' => $e::class,
                'mailer' => config('mail.default'),
            ]);

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
        RateLimiter::clear('2fa:verify:'.$user->id.':'.$request->ip());
        RateLimiter::clear('2fa:resend:'.$user->id.':'.$request->ip());
        RateLimiter::clear('2fa:resend-cooldown:'.$user->id.':'.$request->ip());

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
