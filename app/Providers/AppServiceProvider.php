<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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

        /*
        * Limite para consultar status de sincronização via JavaScript.
        * Como o dashboard consulta de tempos em tempos, precisa ser mais flexível.
        */
        RateLimiter::for('sync-status', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
