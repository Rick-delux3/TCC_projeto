    <?php

    use Illuminate\Foundation\Application;
    use Illuminate\Foundation\Configuration\Exceptions;
    use Illuminate\Foundation\Configuration\Middleware;
    use Illuminate\Auth\Middleware\Authenticate;
    use App\Http\Middleware\TwoFactorMiddleware;

    return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
            commands: __DIR__.'/../routes/console.php',
            health: '/up',
        )
        ->withMiddleware(function (Middleware $middleware): void {
            // ✅ Define para onde redirecionar caso o usuário não esteja logado
            $middleware->redirectTo = '/empresa/login';

            // ✅ Cria apelidos para usar nas rotas
            $middleware->alias([
                'auth' => Authenticate::class,
                '2fa' => TwoFactorMiddleware::class,
            ]);
        })
        ->withExceptions(function (Exceptions $exceptions): void {
            //
        })->create();
