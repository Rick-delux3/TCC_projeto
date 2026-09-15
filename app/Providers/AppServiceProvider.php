<?php

namespace App\Providers;

use App\Models\Corretor;
use App\Support\CorretorPermissions;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return rtrim((string) config('app.url'), '/').route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false);
        });

        /*
        * Limite para abrir páginas de simulação.
        * Pode ser mais alto, porque o usuário/imobiliária pode acessar várias vezes.
        */
        RateLimiter::for('simulation-page', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        /*
        * Limite para ENVIO da simulação.
        * Esse precisa ser mais protegido para evitar spam.
        */
        RateLimiter::for('simulation-submit', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('company-code-recovery', function (Request $request): array {
            $email = $request->input('email');
            $normalizedEmail = is_string($email) ? mb_strtolower(trim($email)) : '';
            $response = function (Request $request, array $headers): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse {
                $message = 'Muitas solicitações. Aguarde antes de solicitar o código novamente.';

                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 429, $headers);
                }

                return redirect()->route('simulation.registered-company.code.request')
                    ->with('company_code_retry_after', (int) ($headers['Retry-After'] ?? 60))
                    ->withErrors(['email' => $message])
                    ->withHeaders($headers);
            };

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip())->response($response),
                Limit::perHour(3)->by('email:'.hash('sha256', $normalizedEmail))->response($response),
            ];
        });

        Gate::before(function ($user, string $ability) {
            if (
                $ability === 'create-analysis'
                && ! config('features.insurance_analysis.enabled', false)
            ) {
                return false;
            }

            if ($user instanceof Corretor && $user->isActive() && $user->isCeo()) {
                return true;
            }

            return null;
        });

        Gate::define('access-dashboard', function (Corretor $corretor) {
            return $corretor->isActive();
        });

        Gate::define('access-simulation-forms', function (Corretor $corretor) {
            return $corretor->isActive();
        });

        Gate::define('manage-organization', function (Corretor $corretor) {
            return $corretor->isActive()
                && $corretor->isCeo();
        });

        foreach (CorretorPermissions::abilities() as $ability => $permissions) {
            Gate::define($ability, function (Corretor $corretor) use ($permissions) {
                return $corretor->isActive()
                    && collect($permissions)->contains(
                        fn (string $permission): bool => $corretor->hasPermission($permission)
                            && collect(CorretorPermissions::dependenciesFor($permission))->every(
                                fn (string $dependency): bool => $corretor->hasPermission($dependency)
                            )
                    );
            });
        }

    }
}
