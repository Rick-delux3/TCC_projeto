<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\CorretorLoginVerificationCode;
use App\Services\CorretorFirstLoginCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class CorretorFirstLoginVerificationController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $corretor = Auth::guard('corretor')->user();

        if ($corretor->hasVerifiedFirstLogin()) {
            return redirect()->route('corretor.dashboard');
        }

        return view('corretor.auth.first-login-verify');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'digits:6'],
        ], [
            'code.required' => 'Informe o código enviado para seu e-mail.',
            'code.digits' => 'O código deve ter exatamente 6 dígitos.',
        ]);

        $corretor = Auth::guard('corretor')->user();

        if ($corretor->hasVerifiedFirstLogin()) {
            return redirect()->route('corretor.dashboard');
        }

        $rateKey = 'corretor-first-login-verify:' . $corretor->id . ':' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return back()->withErrors([
                'code' => "Muitas tentativas. Tente novamente em {$seconds} segundos.",
            ]);
        }

        $verification = CorretorLoginVerificationCode::where('corretor_id', $corretor->id)
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

            RateLimiter::hit($rateKey, 600);

            return back()->withErrors([
                'code' => 'Código inválido.',
            ]);
        }

        DB::transaction(function () use ($corretor, $verification) {
            $verification->update([
                'used_at' => now(),
            ]);

            $corretor->forceFill([
                'first_login_verified_at' => now(),
                'last_login_at' => now(),
            ])->save();
        });

        RateLimiter::clear($rateKey);

        $request->session()->regenerate();

        return redirect()
            ->route('corretor.dashboard')
            ->with('success', 'Primeiro acesso verificado com sucesso!');
    }

    public function resend(Request $request, CorretorFirstLoginCodeService $service): RedirectResponse
    {
        $corretor = Auth::guard('corretor')->user();

        if ($corretor->hasVerifiedFirstLogin()) {
            return redirect()->route('corretor.dashboard');
        }

        $rateKey = 'corretor-first-login-resend:' . $corretor->id . ':' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateKey, 1)) {
            $seconds = RateLimiter::availableIn($rateKey);

            return back()->withErrors([
                'code' => "Aguarde {$seconds} segundos para reenviar outro código.",
            ]);
        }

        RateLimiter::hit($rateKey, 60);

        $service->sendCode($corretor, $request);

        return back()->with('status', 'Um novo código foi enviado para seu e-mail.');
    }
}