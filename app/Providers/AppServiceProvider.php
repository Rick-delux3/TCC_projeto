<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\Corretor;
use Illuminate\Support\Facades\Gate;

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

        Gate::before(function ($user, string $ability) {
            if ($user instanceof Corretor && $user->isActive() && $user->isCeo()) {
                return true;
            }

            return null;
        });

        Gate::define('manage-organization', function (Corretor $corretor) {
        return $corretor->isActive()
            && $corretor->isCeo();
        });

        Gate::define('view-leads', function (Corretor $corretor) {
            return $corretor->isActive()
                && $corretor->hasPermission('leads.visualizar');
        });

        Gate::define('edit-leads', function (Corretor $corretor){
            return $corretor->isActive()
                && $corretor->hasPermission('leads.editar');
        });
        
        Gate::define('create-analysis', function (Corretor $corretor) {
            return $corretor->isActive()
                && $corretor->hasPermission('analises.criar');
        });

        Gate::define('view-analyses', function (Corretor $corretor) {
            return $corretor->isActive()
                && $corretor->hasPermission('analises.visualizar');
        });

        Gate::define('view-real-estate-companies', function (Corretor $corretor) {
            return $corretor->isActive()
                && $corretor->hasPermission('imobiliarias.visualizar');
        });

        Gate::define('view-tags', function (Corretor $corretor) {
            return $corretor->isActive()
                && $corretor->hasPermission('tags.visualizar');
        });

    }
}
