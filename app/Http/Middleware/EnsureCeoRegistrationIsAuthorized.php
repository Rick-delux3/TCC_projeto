<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCeoRegistrationIsAuthorized
{
    public const SESSION_KEY = 'admin.ceo_registration_authorized_at';

    private const AUTHORIZATION_TTL_MINUTES = 15;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorizedAt = $request->session()->get(self::SESSION_KEY);
        $authorizationIsValid = is_int($authorizedAt)
            && $authorizedAt <= now()->getTimestamp()
            && $authorizedAt >= now()->subMinutes(self::AUTHORIZATION_TTL_MINUTES)->getTimestamp();

        if (! $authorizationIsValid) {
            $request->session()->forget(self::SESSION_KEY);

            if ($request->isMethod('GET')) {
                return redirect()->route('admin.ceo.register.access');
            }

            abort(403, 'Autorização inválida ou expirada para cadastro do CEO.');
        }

        return $next($request);
    }
}
