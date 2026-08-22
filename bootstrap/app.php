    <?php

    use Illuminate\Foundation\Application;
    use Illuminate\Foundation\Configuration\Exceptions;
    use Illuminate\Foundation\Configuration\Middleware;
    use Illuminate\Auth\Middleware\Authenticate;
    use App\Http\Middleware\CorretorTwoFactorMiddleware;
    use App\Http\Middleware\TwoFactorMiddleware;
    use App\Http\Middleware\EnsureCeoRegistrationIsOpen;
    use App\Http\Middleware\EnsureInsuranceAnalysisEnabled;
    use App\Http\Middleware\PreventAuthenticationFraming;
    use App\Http\Middleware\CorretorAuth;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;


    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
            commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
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
                    $request->routeIs('admin.ceo.*') ||
                    $request->is('ceo/admin/*')
                ) {
                    return route('admin.ceo.login');
                }

                /*
                |--------------------------------------------------------------------------
                | Área da imobiliária
                |--------------------------------------------------------------------------
                */
                if (
                    $request->routeIs('Dashboard-Admin') ||
                    $request->routeIs('admin.*') ||
                    $request->is('Dashboard/Admin*') ||
                    $request->is('dashboard/admin*') ||
                    $request->is('admin/*') ||
                    $request->is('admins/*') ||
                    $request->is('equipe') ||
                    $request->is('equipe/*')
                ) {
                    return route('admin.login');
                }

                 if (
                    $request->routeIs('empresa.*') ||
                    $request->routeIs('Dashboard') ||
                    $request->routeIs('2fa.*') ||
                    $request->is('Dashboard/User*') ||
                    $request->is('dashboard/user*') ||
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

            $middleware->redirectUsersTo(function (Request $request) {
                if (Auth::guard('admin')->check()) {
                    return route('Dashboard-Admin');
                }

                if (Auth::guard('company')->check()) {
                    return route('company.dashboard');
                }

                if (Auth::guard('web')->check()) {
                    return route('dashboard');
                }

                return route('index');
            });

            
            $middleware->alias([
                'auth' => Authenticate::class,
                '2fa' => TwoFactorMiddleware::class,
                'admin.2fa' => CorretorTwoFactorMiddleware::class,
                'corretor.active' => CorretorAuth::class,
                'ceo.registration.open' => EnsureCeoRegistrationIsOpen::class,
                'analysis.enabled' => EnsureInsuranceAnalysisEnabled::class,
                'auth.unframed' => PreventAuthenticationFraming::class,
            ]);
        })
        ->withExceptions(function (Exceptions $exceptions): void {
            //
        })->create();
