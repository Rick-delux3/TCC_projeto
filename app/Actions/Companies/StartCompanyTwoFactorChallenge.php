<?php

namespace App\Actions\Companies;

use App\Models\TwoFactorCode;
use App\Models\User;
use App\Services\CompanyTwoFactorMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class StartCompanyTwoFactorChallenge
{
    public function __construct(private CompanyTwoFactorMailService $twoFactorMail) {}

    public function execute(User $user, Request $request): bool
    {
        $request->session()->forget(['2fa_passed', 'company_id', 'url.intended']);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('company_id', $user->company_id);

        try {
            TwoFactorCode::query()->where('user_id', $user->id)->delete();

            $code = (string) random_int(100000, 999999);
            $expiresAt = now()->addMinutes(10);

            TwoFactorCode::query()->create([
                'user_id' => $user->id,
                'code' => Hash::make($code),
                'expires_at' => $expiresAt,
            ]);

            $this->twoFactorMail->sendCode($user->company->email, $code, $expiresAt);
        } catch (Throwable $exception) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Log::error('Falha ao iniciar o 2FA da imobiliária.', [
                'user_id' => $user->id,
                'exception' => $exception::class,
                'mailer' => config('mail.default'),
            ]);

            TwoFactorCode::query()->where('user_id', $user->id)->delete();

            return false;
        }

        RateLimiter::clear('2fa:verify:'.$user->id.':'.$request->ip());
        RateLimiter::clear('2fa:resend:'.$user->id.':'.$request->ip());
        RateLimiter::clear('2fa:resend-cooldown:'.$user->id.':'.$request->ip());

        return true;
    }
}
