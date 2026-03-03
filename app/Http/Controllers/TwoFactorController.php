<?php

namespace App\Http\Controllers;

use App\Models\TwoFactorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class TwoFactorController extends Controller
{
    private const VERIFY_MAX_ATTEMPTS = 5;
    private const VERIFY_DECAY_SECONDS = 600; // 10 min
    private const RESEND_MAX_ATTEMPTS = 3;
    private const RESEND_DECAY_SECONDS = 600; // 10 min
    private const RESEND_COOLDOWN_SECONDS = 60; // 1 min

    public function index()
    {
        return view('auth.2fa');
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => 'required|digits:6']);

        $userId = Auth::id();
        $verifyKey = $this->verifyThrottleKey($userId, $request->ip());

        if (RateLimiter::tooManyAttempts($verifyKey, self::VERIFY_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($verifyKey);

            return back()->withErrors([
                'code' => "Muitas tentativas. Tente novamente em {$seconds} segundos.",
            ]);
        }

        $code = TwoFactorCode::where('user_id', $userId)
            ->where('code', $request->code)
            ->where('expires_at', '>=', now())
            ->latest('id')
            ->first();

        if (!$code) {
            RateLimiter::hit($verifyKey, self::VERIFY_DECAY_SECONDS);

            return back()->withErrors(['code' => 'Codigo invalido ou expirado.']);
        }

        session(['2fa_passed' => true]);
        RateLimiter::clear($verifyKey);

        // Cleanup all codes so the challenge cannot be replayed.
        TwoFactorCode::where('user_id', $userId)->delete();

        return redirect('/Dashboard/User');
    }

    public function resend(Request $request)
    {
        $user = Auth::user();

        if (!$user) {
            return redirect()->route('empresa.login')->withErrors('Usuario nao autenticado.');
        }

        $resendKey = $this->resendThrottleKey($user->id, $request->ip());
        $cooldownKey = $this->resendCooldownKey($user->id, $request->ip());

        if (RateLimiter::tooManyAttempts($resendKey, self::RESEND_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($resendKey);

            return back()->withErrors([
                'code' => "Limite de reenvio atingido. Tente novamente em {$seconds} segundos.",
            ]);
        }

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $seconds = RateLimiter::availableIn($cooldownKey);

            return back()->with('info', "Aguarde {$seconds} segundos para reenviar o codigo.");
        }

        RateLimiter::hit($resendKey, self::RESEND_DECAY_SECONDS);
        RateLimiter::hit($cooldownKey, self::RESEND_COOLDOWN_SECONDS);

        // Invalidate older codes and send a fresh one.
        TwoFactorCode::where('user_id', $user->id)->delete();

        $code = rand(100000, 999999);

        TwoFactorCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::send('emails.2fa-code', ['code' => $code], function ($message) use ($user) {
            $message->to($user->email)->subject('Seu novo codigo de verificacao');
        });

        return back()->with('success', 'Novo codigo enviado para seu e-mail.');
    }

    private function verifyThrottleKey(int|string|null $userId, string $ip): string
    {
        return '2fa:verify:' . $userId . ':' . $ip;
    }

    private function resendThrottleKey(int|string $userId, string $ip): string
    {
        return '2fa:resend:' . $userId . ':' . $ip;
    }

    private function resendCooldownKey(int|string $userId, string $ip): string
    {
        return '2fa:resend-cooldown:' . $userId . ':' . $ip;
    }
}
