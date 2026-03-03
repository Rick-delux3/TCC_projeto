<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;


class AdminAuthController extends Controller
{
    private const VERIFY_MAX_ATTEMPTS = 5;
    private const VERIFY_DECAY_SECONDS = 600;
    private const RESEND_MAX_ATTEMPTS = 3;
    private const RESEND_DECAY_SECONDS = 600;
    private const RESEND_COOLDOWN_SECONDS = 60;

    public function showLoginForm()
    {
        return view('admin-login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'cpf' => 'required|string|regex:/^\d{11}$/',
            'password' => 'required|string|min:6',
        ]);

        if (!Auth::guard('admin')->attempt([
            'cpf' => $data['cpf'],
            'password' => $data['password'],
        ])) {
            return back()
                ->withInput($request->only('cpf'))
                ->withErrors(['cpf' => 'CPF ou senha incorretos.']);
        }

        $request->session()->regenerate();

        $admin = Auth::guard('admin')->user();
        if (!$admin) {
            return redirect()->route('admin.login')->withErrors(['cpf' => 'Falha ao iniciar sessao administrativa.']);
        }

        $this->sendTwoFactorCode((int) $admin->id, $admin->email);

        session()->forget('admin_2fa_passed');

        RateLimiter::clear($this->verifyThrottleKey((int) $admin->id, $request->ip()));
        RateLimiter::clear($this->resendThrottleKey((int) $admin->id, $request->ip()));
        RateLimiter::clear($this->resendCooldownKey((int) $admin->id, $request->ip()));

        return redirect()->route('admin.2fa.form')->with('success', 'Codigo enviado ao seu e-mail.');
    }

    public function showTwoFactorForm()
    {
        if (!Auth::guard('admin')->check()) {
            return redirect()->route('admin.login');
        }

        return view('auth.admin-2fa');
    }

    public function verifyTwoFactor(Request $request)
    {
        $request->validate(['code' => 'required|digits:6']);

        $admin = Auth::guard('admin')->user();
        if (!$admin) {
            return redirect()->route('admin.login');
        }

        $adminId = (int) $admin->id;
        $verifyKey = $this->verifyThrottleKey($adminId, $request->ip());

        if (RateLimiter::tooManyAttempts($verifyKey, self::VERIFY_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($verifyKey);
            return back()->withErrors(['code' => "Muitas tentativas. Tente novamente em {$seconds} segundos."]);
        }

        $payload = Cache::get($this->twoFactorCacheKey($adminId));
        $validCode = is_array($payload) ? ($payload['code'] ?? null) : null;

        if (!$validCode || $validCode !== $request->code) {
            RateLimiter::hit($verifyKey, self::VERIFY_DECAY_SECONDS);
            return back()->withErrors(['code' => 'Codigo invalido ou expirado.']);
        }

        session(['admin_2fa_passed' => true]);
        Cache::forget($this->twoFactorCacheKey($adminId));
        RateLimiter::clear($verifyKey);

        return redirect()->route('Dashboard-Admin');
    }

    public function resendTwoFactor(Request $request)
    {
        $admin = Auth::guard('admin')->user();
        if (!$admin) {
            return redirect()->route('admin.login');
        }

        $adminId = (int) $admin->id;
        $resendKey = $this->resendThrottleKey($adminId, $request->ip());
        $cooldownKey = $this->resendCooldownKey($adminId, $request->ip());

        if (RateLimiter::tooManyAttempts($resendKey, self::RESEND_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($resendKey);
            return back()->withErrors(['code' => "Limite de reenvio atingido. Tente novamente em {$seconds} segundos."]);
        }

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $seconds = RateLimiter::availableIn($cooldownKey);
            return back()->with('info', "Aguarde {$seconds} segundos para reenviar o codigo.");
        }

        RateLimiter::hit($resendKey, self::RESEND_DECAY_SECONDS);
        RateLimiter::hit($cooldownKey, self::RESEND_COOLDOWN_SECONDS);

        $this->sendTwoFactorCode($adminId, $admin->email);

        return back()->with('success', 'Novo codigo enviado para seu e-mail.');
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'Logout realizado com sucesso.');
    }

    private function sendTwoFactorCode(int $adminId, string $email): void
    {
        $code = (string) random_int(100000, 999999);

        Cache::put($this->twoFactorCacheKey($adminId), [
            'code' => $code,
        ], now()->addMinutes(10));

        Mail::send('emails.admin-2fa-code', ['code' => $code], function ($message) use ($email) {
            $message->to($email)->subject('Seu codigo de verificacao administrativa');
        });
    }

    private function twoFactorCacheKey(int $adminId): string
    {
        return 'admin:2fa:code:' . $adminId;
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
