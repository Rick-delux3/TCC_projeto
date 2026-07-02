<?php

namespace App\Http\Controllers;

use App\Models\CorretorLoginVerificacaoCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class CorretorAuthController extends Controller
{
    private const LOGIN_MAX_ATTEMPTS = 5;
    private const LOGIN_DECAY_SECONDS = 300;

    private const VERIFY_MAX_ATTEMPTS = 5;
    private const VERIFY_DECAY_SECONDS = 600;

    private const RESEND_MAX_ATTEMPTS = 3;
    private const RESEND_DECAY_SECONDS = 600;
    private const RESEND_COOLDOWN_SECONDS = 60;

    public function showLoginForm()
    {
        return view('admin-ceo-login');
    }

    public function login(Request $request)
    {
        $request->merge([
            'cpf' => preg_replace('/\D/', '', (string) $request->cpf),
        ]);

        $data = $request->validate([
            'cpf' => 'required|string|regex:/^\d{11}$/',
            'password' => 'required|string|min:6',
        ], [
            'cpf.required' => 'Informe o CPF.',
            'cpf.regex' => 'Informe um CPF válido com 11 números.',
            'password.required' => 'Informe a senha.',
            'password.min' => 'A senha deve ter pelo menos 6 caracteres.',
        ]);

        $loginKey = $this->loginThrottleKey($data['cpf'], $request->ip());

        if (RateLimiter::tooManyAttempts($loginKey, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($loginKey);

            return back()
                ->withInput($request->only('cpf'))
                ->withErrors([
                    'cpf' => "Muitas tentativas de login. Tente novamente em {$seconds} segundos.",
                ]);
        }

        if (! Auth::guard('admin')->attempt([
            'cpf' => $data['cpf'],
            'password' => $data['password'],
        ])) {
            RateLimiter::hit($loginKey, self::LOGIN_DECAY_SECONDS);

            return back()
                ->withInput($request->only('cpf'))
                ->withErrors([
                    'cpf' => 'CPF ou senha incorretos.',
                ]);
        }

        RateLimiter::clear($loginKey);

        $request->session()->regenerate();

        $admin = Auth::guard('admin')->user();

        if (! $admin) {
            return redirect()
                ->route('admin.login')
                ->withErrors([
                    'cpf' => 'Falha ao iniciar sessão administrativa.',
                ]);
        }

        if (! $admin->isActive()) {
            Auth::guard('admin')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('admin.login')
                ->withErrors([
                    'cpf' => 'Este corretor está inativo. Entre em contato com o administrador.',
                ]);
        }

        if(! $admin->isCeo())
        {
            Auth::guard('admin')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors([
                'cpf' => 'Acesso negado. Apenas o CEO pode acessar esta área.',
            ]);
        }

        if (! $admin->hasVerifiedFirstLogin()) {
            $this->sendTwoFactorCode($admin, $request);

            session()->forget('admin_2fa_passed');

            RateLimiter::clear($this->verifyThrottleKey((int) $admin->id, $request->ip()));
            RateLimiter::clear($this->resendThrottleKey((int) $admin->id, $request->ip()));
            RateLimiter::clear($this->resendCooldownKey((int) $admin->id, $request->ip()));

            return redirect()
                ->route('admin.2fa.form')
                ->with('success', 'Código enviado ao seu e-mail para confirmar o primeiro acesso.');
        }

        $admin->forceFill([
            'last_login_at' => now(),
        ])->save();

        session(['admin_2fa_passed' => true]);

        return redirect()->route('Dashboard-Admin');
    }

    public function showTwoFactorForm()
    {
        if (! Auth::guard('admin')->check()) {
            return redirect()->route('admin.login');
        }

        $admin = Auth::guard('admin')->user();

        if ($admin && $admin->hasVerifiedFirstLogin()) {
            return redirect()->route('Dashboard-Admin');
        }

        return view('auth.admin-2fa');
    }

    public function verifyTwoFactor(Request $request)
    {
        $request->validate([
            'code' => 'required|digits:6',
        ], [
            'code.required' => 'Informe o código enviado para seu e-mail.',
            'code.digits' => 'O código deve ter exatamente 6 dígitos.',
        ]);

        $admin = Auth::guard('admin')->user();

        if (! $admin) {
            return redirect()->route('admin.login');
        }

        if ($admin->hasVerifiedFirstLogin()) {
            session(['admin_2fa_passed' => true]);

            return redirect()->route('Dashboard-Admin');
        }

        $adminId = (int) $admin->id;
        $verifyKey = $this->verifyThrottleKey($adminId, $request->ip());

        if (RateLimiter::tooManyAttempts($verifyKey, self::VERIFY_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($verifyKey);

            return back()->withErrors([
                'code' => "Muitas tentativas. Tente novamente em {$seconds} segundos.",
            ]);
        }

        $verification = CorretorLoginVerificacaoCode::where('corretor_id', $adminId)
            ->whereNull('used_at')
            ->latest()
            ->first();

        if (! $verification) {
            return back()->withErrors([
                'code' => 'Nenhum código ativo foi encontrado. Solicite um novo código.',
            ]);
        }

        if ($verification->isExpired()) {
            $verification->update([
                'used_at' => now(),
            ]);

            return back()->withErrors([
                'code' => 'Este código expirou. Solicite um novo código.',
            ]);
        }

        if ($verification->reachedMaxAttempts()) {
            return back()->withErrors([
                'code' => 'Este código atingiu o limite de tentativas. Solicite um novo código.',
            ]);
        }

        if (! Hash::check($request->code, $verification->code_hash)) {
            $verification->increment('attempts');

            RateLimiter::hit($verifyKey, self::VERIFY_DECAY_SECONDS);

            return back()->withErrors([
                'code' => 'Código inválido.',
            ]);
        }

        DB::transaction(function () use ($admin, $verification) {
            $verification->update([
                'used_at' => now(),
            ]);

            $admin->forceFill([
                'first_login_verified_at' => now(),
                'last_login_at' => now(),
            ])->save();
        });

        session(['admin_2fa_passed' => true]);

        RateLimiter::clear($verifyKey);

        $request->session()->regenerate();

        return redirect()
            ->route('Dashboard-Admin')
            ->with('success', 'Primeiro acesso verificado com sucesso!');
    }

    public function resendTwoFactor(Request $request)
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin) {
            return redirect()->route('admin.login');
        }

        if ($admin->hasVerifiedFirstLogin()) {
            return redirect()->route('Dashboard-Admin');
        }

        $adminId = (int) $admin->id;
        $resendKey = $this->resendThrottleKey($adminId, $request->ip());
        $cooldownKey = $this->resendCooldownKey($adminId, $request->ip());

        if (RateLimiter::tooManyAttempts($resendKey, self::RESEND_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($resendKey);

            return back()->withErrors([
                'code' => "Limite de reenvio atingido. Tente novamente em {$seconds} segundos.",
            ]);
        }

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $seconds = RateLimiter::availableIn($cooldownKey);

            return back()->with('info', "Aguarde {$seconds} segundos para reenviar o código.");
        }

        RateLimiter::hit($resendKey, self::RESEND_DECAY_SECONDS);
        RateLimiter::hit($cooldownKey, self::RESEND_COOLDOWN_SECONDS);

        $this->sendTwoFactorCode($admin, $request);

        return back()->with('success', 'Novo código enviado para seu e-mail.');
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('admin.login')
            ->with('success', 'Logout realizado com sucesso.');
    }

    private function sendTwoFactorCode($admin, Request $request): void
    {
        CorretorLoginVerificacaoCode::where('corretor_id', $admin->id)
            ->whereNull('used_at')
            ->update([
                'used_at' => now(),
            ]);

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);

        CorretorLoginVerificacaoCode::create([
            'corretor_id' => $admin->id,
            'code_hash' => Hash::make($code),
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'used_at' => null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);

        $admin->forceFill([
            'first_login_code_sent_at' => now(),
        ])->save();

        Mail::send('emails.admin-2fa-code', [
            'code' => $code,
            'expiresAt' => $expiresAt->format('H:i'),
            'admin' => $admin,
        ], function ($message) use ($admin) {
            $message
                ->to($admin->email)
                ->subject('Seu código de verificação administrativa');
        });
    }

    private function loginThrottleKey(string $cpf, string $ip): string
    {
        return 'admin:login:' . $cpf . ':' . $ip;
    }

    private function verifyThrottleKey(int $adminId, string $ip): string
    {
        return 'admin:2fa:verify:' . $adminId . ':' . $ip;
    }

    private function resendThrottleKey(int $adminId, string $ip): string
    {
        return 'admin:2fa:resend:' . $adminId . ':' . $ip;
    }

    private function resendCooldownKey(int $adminId, string $ip): string
    {
        return 'admin:2fa:resend-cooldown:' . $adminId . ':' . $ip;
    }
}