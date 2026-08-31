<?php

namespace App\Http\Controllers;

use App\Models\Corretor;
use App\Models\CorretorLoginVerificacaoCode;
use App\Services\CorretorInvitationService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class CorretorAuthController extends Controller
{
    public function __construct(
        private CorretorInvitationService $invitationService
    ) {}

    private const LOGIN_MAX_ATTEMPTS = 5;

    private const LOGIN_DECAY_SECONDS = 300;

    private const VERIFY_MAX_ATTEMPTS = 5;

    private const VERIFY_DECAY_SECONDS = 600;

    private const RESEND_MAX_ATTEMPTS = 3;

    private const RESEND_DECAY_SECONDS = 600;

    private const RESEND_COOLDOWN_SECONDS = 60;

    public function memberShowLoginForm()
    {
        return view('corretor.admin-member-login');
    }

    public function ceoShowLoginForm()
    {
        return view('corretor.admin-ceo-login');
    }

    public function ceoLogin(Request $request)
    {
        $request->merge([
            'cpf' => preg_replace('/\D/', '', (string) $request->cpf),
        ]);

        $data = $request->validate([
            'cpf' => 'required|string|regex:/^\d{11}$/',
            'password' => 'required|string|max:72',
        ], [
            'cpf.required' => 'Informe o CPF.',
            'cpf.regex' => 'Informe um CPF válido com 11 números.',
            'password.required' => 'Informe a senha.',
            'password.max' => 'A senha deve ter no máximo 72 caracteres.',
        ]);

        $loginKey = $this->loginThrottleKey('ceo:'.$data['cpf'], $request->ip());

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

        $corretor = Auth::guard('admin')->user();

        if (! $corretor) {
            return redirect()
                ->route('admin.ceo.login')
                ->withErrors([
                    'cpf' => 'Falha ao iniciar sessão administrativa.',
                ]);
        }

        if (! $corretor->isCeo()) {
            $this->logoutCurrentAdminSession($request);

            return redirect()->route('admin.ceo.login')->withErrors([
                'cpf' => 'Acesso negado. Apenas o CEO pode acessar esta área.',
            ]);
        }

        return $this->finishSuccessfulLogin($corretor, $request, 'admin.ceo.login');

    }

    public function memberLogin(Request $request)
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->email)),
        ]);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
        ],
            [
                'email.required' => 'Informe o e-mail.',
                'email.email' => 'Informe um e-mail válido.',
                'password.required' => 'Informe a senha.',
                'password.max' => 'A senha deve ter no máximo 72 caracteres.',
            ]
        );

        $loginKey = $this->loginThrottleKey('member:'.$data['email'], $request->ip());

        if (RateLimiter::tooManyAttempts($loginKey, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($loginKey);

            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => "Muitas tentativas de login. Tente novamente em {$seconds} segundos.",
                ]);
        }

        if (! Auth::guard('admin')->attempt([
            'email' => $data['email'],
            'password' => $data['password'],
        ])) {
            RateLimiter::hit($loginKey, self::LOGIN_DECAY_SECONDS);

            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => 'E-mail ou senha incorretos.',
                ]);
        }

        RateLimiter::clear($loginKey);

        $request->session()->regenerate();

        $corretor = Auth::guard('admin')->user();

        if (! $corretor) {
            return redirect()
                ->route('admin.login')
                ->withErrors([
                    'email' => 'Falha ao iniciar sessão administrativa.',
                ]);
        }

        if (! $corretor->isIntegrante()) {
            $this->logoutCurrentAdminSession($request);

            return redirect()->route('admin.login')->withErrors([
                'email' => 'Acesso negado. Esta área é exclusiva para Integrantes',
            ]);
        }

        return $this->finishSuccessfulLogin($corretor, $request, 'admin.login', true);

    }

    private function finishSuccessfulLogin(Corretor $corretor, Request $request, string $fallbackLoginRoute, bool $acceptMemberInvitation = false)
    {
        if (! $corretor->isActive()) {
            $this->logoutCurrentAdminSession($request);

            return redirect()
                ->route($fallbackLoginRoute)
                ->withErrors([
                    'email' => 'Este corretor está inativo. Entre em contato com o CEO da corretora.',
                    'cpf' => 'Este corretor está inativo. Entre em contato com o CEO da corretora.',
                ]);
        }

        if ($acceptMemberInvitation) {
            try {
                $this->invitationService->assertFirstLoginCanContinue(
                    request: $request,
                    integrante: $corretor
                );

                $this->invitationService->acceptAfterSuccessfulLogin(
                    request: $request,
                    integrante: $corretor
                );
            } catch (DomainException $exception) {
                $this->logoutCurrentAdminSession($request);

                return redirect()
                    ->route($fallbackLoginRoute)
                    ->withErrors([
                        'email' => $exception->getMessage(),
                    ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Guarda qual tela de login deve ser usada caso a sessão expire.
        |--------------------------------------------------------------------------
        */
        session([
            'admin_login_fallback_route' => $fallbackLoginRoute,
        ]);

        /*
        |--------------------------------------------------------------------------
        | 2FA único de primeiro acesso
        |--------------------------------------------------------------------------
        | Se first_login_verified_at estiver nulo, envia código e bloqueia
        | o acesso ao dashboard até validar.
        */
        if (! $corretor->hasVerifiedFirstLogin()) {
            try {
                $this->sendTwoFactorCode($corretor, $request);
            } catch (\Throwable $exception) {
                Log::error('Falha ao enviar código de 2FA administrativo.', [
                    'corretor_id' => $corretor->id,
                    'exception' => $exception::class,
                    'mailer' => config('mail.default'),
                ]);

                $this->logoutCurrentAdminSession($request);

                return redirect()
                    ->route($fallbackLoginRoute)
                    ->withErrors([
                        'email' => 'Não foi possível enviar o código de verificação. Tente novamente.',
                        'cpf' => 'Não foi possível enviar o código de verificação. Tente novamente.',
                    ]);
            }

            session()->forget('admin_2fa_passed');

            RateLimiter::clear($this->verifyThrottleKey((int) $corretor->id, $request->ip()));
            RateLimiter::clear($this->resendThrottleKey((int) $corretor->id, $request->ip()));
            RateLimiter::clear($this->resendCooldownKey((int) $corretor->id, $request->ip()));

            return redirect()
                ->route('admin.2fa.form')
                ->with('success', 'Código enviado ao seu e-mail para confirmar o primeiro acesso.');
        }

        $corretor->forceFill([
            'last_login_at' => now(),
        ])->save();

        session(['admin_2fa_passed' => true]);

        return redirect()->route('Dashboard-Admin');
    }

    public function showTwoFactorForm()
    {
        if (! Auth::guard('admin')->check()) {
            return redirect()->route(session(
                'admin_login_fallback_route', 'admin.login'
            ));
        }

        $corretor = Auth::guard('admin')->user();

        if ($corretor && $corretor->hasVerifiedFirstLogin()) {
            session(['admin_2fa_passed' => true]);

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

        $corretor = Auth::guard('admin')->user();

        if (! $corretor) {
            return redirect()->route(session('admin_login_fallback_route', 'admin.login'));
        }

        if ($corretor->hasVerifiedFirstLogin()) {
            session(['admin_2fa_passed' => true]);

            return redirect()->route('Dashboard-Admin');
        }

        $corretorId = (int) $corretor->id;
        $verifyKey = $this->verifyThrottleKey($corretorId, $request->ip());

        if (RateLimiter::tooManyAttempts($verifyKey, self::VERIFY_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($verifyKey);

            return back()->withErrors([
                'code' => "Muitas tentativas. Tente novamente em {$seconds} segundos.",
            ]);
        }

        $verificationResult = DB::transaction(function () use ($corretor, $corretorId, $request): string {
            $verification = CorretorLoginVerificacaoCode::query()
                ->where('corretor_id', $corretorId)
                ->whereNull('used_at')
                ->latest()
                ->lockForUpdate()
                ->first();

            if (! $verification) {
                return 'missing';
            }

            if ($verification->isExpired()) {
                $verification->forceFill(['used_at' => now()])->save();

                return 'expired';
            }

            if ($verification->reachedMaxAttempts()) {
                $verification->forceFill(['used_at' => now()])->save();

                return 'locked';
            }

            if (! Hash::check($request->code, $verification->code_hash)) {
                $incremented = CorretorLoginVerificacaoCode::query()
                    ->whereKey($verification->id)
                    ->whereNull('used_at')
                    ->where('attempts', '<', self::VERIFY_MAX_ATTEMPTS)
                    ->increment('attempts');

                if ($incremented !== 1) {
                    CorretorLoginVerificacaoCode::query()
                        ->whereKey($verification->id)
                        ->whereNull('used_at')
                        ->update(['used_at' => now()]);

                    return 'locked';
                }

                CorretorLoginVerificacaoCode::query()
                    ->whereKey($verification->id)
                    ->whereNull('used_at')
                    ->where('attempts', '>=', self::VERIFY_MAX_ATTEMPTS)
                    ->update(['used_at' => now()]);

                return 'invalid';
            }

            $verifiedAt = now();
            $claimed = CorretorLoginVerificacaoCode::query()
                ->whereKey($verification->id)
                ->whereNull('used_at')
                ->where('attempts', '<', self::VERIFY_MAX_ATTEMPTS)
                ->update(['used_at' => $verifiedAt]);

            if ($claimed !== 1) {
                CorretorLoginVerificacaoCode::query()
                    ->whereKey($verification->id)
                    ->whereNull('used_at')
                    ->update(['used_at' => $verifiedAt]);

                return 'locked';
            }

            $corretor->forceFill([
                'first_login_verified_at' => $verifiedAt,
                'last_login_at' => $verifiedAt,
            ])->save();

            return 'verified';
        });

        if ($verificationResult !== 'verified') {
            if ($verificationResult === 'invalid') {
                RateLimiter::hit($verifyKey, self::VERIFY_DECAY_SECONDS);
            }

            $message = match ($verificationResult) {
                'missing' => 'Nenhum código ativo foi encontrado. Solicite um novo código.',
                'expired' => 'Este código expirou. Solicite um novo código.',
                'locked' => 'Este código atingiu o limite de tentativas. Solicite um novo código.',
                default => 'Código inválido.',
            };

            return back()->withErrors(['code' => $message]);
        }

        session(['admin_2fa_passed' => true]);

        RateLimiter::clear($verifyKey);

        $request->session()->regenerate();

        return redirect()
            ->route('Dashboard-Admin')
            ->with('success', 'Primeiro acesso verificado com sucesso!');
    }

    public function resendTwoFactor(Request $request)
    {
        $corretor = Auth::guard('admin')->user();

        if (! $corretor) {
            return redirect()->route(session('admin_login_fallback_route', 'admin.login'));
        }

        if ($corretor->hasVerifiedFirstLogin()) {
            session(['admin_2fa_passed' => true]);

            return redirect()->route('Dashboard-Admin');
        }

        $corretorId = (int) $corretor->id;
        $resendKey = $this->resendThrottleKey($corretorId, $request->ip());
        $cooldownKey = $this->resendCooldownKey($corretorId, $request->ip());

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

        try {
            $this->sendTwoFactorCode($corretor, $request);
        } catch (\Throwable $exception) {
            Log::error('Falha ao reenviar código de 2FA administrativo.', [
                'corretor_id' => $corretor->id,
                'exception' => $exception::class,
                'mailer' => config('mail.default'),
            ]);

            return back()->withErrors([
                'code' => 'Não foi possível reenviar o código. Tente novamente.',
            ]);
        }

        return back()->with('success', 'Novo código enviado para seu e-mail.');
    }

    public function logout(Request $request)
    {
        $corretor = Auth::guard('admin')->user();

        $redirectRoute = $corretor && $corretor->isCeo() ? 'admin.ceo.login' : 'admin.login';

        $this->logoutCurrentAdminSession($request);

        return redirect()
            ->route($redirectRoute)
            ->with('success', 'Logout realizado com sucesso.');
    }

    private function sendTwoFactorCode(Corretor $corretor, Request $request): void
    {
        CorretorLoginVerificacaoCode::where('corretor_id', $corretor->id)
            ->whereNull('used_at')
            ->update([
                'used_at' => now(),
            ]);

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);

        CorretorLoginVerificacaoCode::create([
            'corretor_id' => $corretor->id,
            'code_hash' => Hash::make($code),
            'expires_at' => $expiresAt,
            'attempts' => 0,
            'used_at' => null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);

        $corretor->forceFill([
            'first_login_code_sent_at' => now(),
        ])->save();

        try {
            Mail::send('emails.admin-2fa-code', [
                'code' => $code,
                'expiresAt' => $expiresAt->format('H:i T'),
                'admin' => $corretor,
            ], function ($message) use ($corretor) {
                $message
                    ->to($corretor->email)
                    ->subject('Seu código de verificação administrativa');
            });
        } catch (\Throwable $exception) {
            CorretorLoginVerificacaoCode::where('corretor_id', $corretor->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            throw $exception;
        }
    }

    public function acceptMemberInvitation(
        Request $request,
        Corretor $corretor
    ) {
        $routeLogin = route('admin.login');

        if (! $request->hasValidSignature()) {
            $expires = (int) $request->query('expires');

            $message = $expires > 0 && now()->timestamp > $expires
            ? 'Este convite expirou. Solicite ao CEO um novo convite.'
            : 'Este convite é inválido ou foi alterado.';

            return redirect($routeLogin)->withErrors([
                'email' => $message,
            ]);
        }

        try {
            if (Auth::guard('admin')->check()) {
                $this->logoutCurrentAdminSession($request);
            }

            $this->invitationService->rememberValidInvitation(
                request: $request,
                integrante: $corretor,
                version: (int) $request->query('version')
            );

            return redirect()->route('admin.login', ['email' => $corretor->email])
                ->with(
                    'success',
                    'Convite validado. Informe seu email e senha para continuar.'
                );

        } catch (DomainException $exception) {
            return redirect($routeLogin)->withErrors([
                'email' => $exception->getMessage(),
            ]);
        }
    }

    private function logoutCurrentAdminSession(Request $request)
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function loginThrottleKey(string $cpf, string $ip): string
    {
        return 'admin:login:'.$cpf.':'.$ip;
    }

    private function verifyThrottleKey(int $corretorId, string $ip): string
    {
        return 'admin:2fa:verify:'.$corretorId.':'.$ip;
    }

    private function resendThrottleKey(int $corretorId, string $ip): string
    {
        return 'admin:2fa:resend:'.$corretorId.':'.$ip;
    }

    private function resendCooldownKey(int $corretorId, string $ip): string
    {
        return 'admin:2fa:resend-cooldown:'.$corretorId.':'.$ip;
    }
}
