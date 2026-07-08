    <?php

    use Illuminate\Foundation\Application;
    use Illuminate\Foundation\Configuration\Exceptions;
    use Illuminate\Foundation\Configuration\Middleware;
    use Illuminate\Auth\Middleware\Authenticate;
    use App\Http\Middleware\CorretorTwoFactorMiddleware;
    use App\Http\Middleware\TwoFactorMiddleware;
    use App\Http\Middleware\EnsureCeoRegistrationIsOpen;
    use Illuminate\Http\Request;


    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
            commands: __DIR__.'/../routes/console.php',
            health: '/up',
        )
        ->withMiddleware(function (Middleware $middleware): void {
            
            $middleware->redirectGuestsTo(function (Request $request) {
                /*
                |--------------------------------------------------------------------------
                | Área dos corretores/admins
                |--------------------------------------------------------------------------
                */
                if (    
                    $request->is('Dashboard/Admin*') ||
                    $request->is('admin/*') ||
                    $request->is('admins/*') ||
                    $request->is('ceo/admin/*')
                ) {
                    return route(session('admin_login_fallback_route', 'admin.login'));
                }

                /*
                |--------------------------------------------------------------------------
                | Área da imobiliária
                |--------------------------------------------------------------------------
                */
                if (
                    $request->is('Dashboard/User*') ||
                    $request->is('empresa/*') ||
                    $request->is('2fa*')
                ) {
                    return route('empresa.login');
                }

                /*
                |--------------------------------------------------------------------------
                | Fallback geral
                |--------------------------------------------------------------------------
                */
                return route('empresa.login');
            });

            
            $middleware->alias([
                'auth' => Authenticate::class,
                '2fa' => TwoFactorMiddleware::class,
                'admin.2fa' => CorretorTwoFactorMiddleware::class,
                'ceo.registration.open' => EnsureCeoRegistrationIsOpen::class,
            ]);
        })
        ->withExceptions(function (Exceptions $exceptions): void {
            //
        })->create();
