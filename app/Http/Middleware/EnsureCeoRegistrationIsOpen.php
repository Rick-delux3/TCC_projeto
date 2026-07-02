<?php

namespace App\Http\Middleware;

use App\Models\Corretor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCeoRegistrationIsOpen
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if(! config('admin.ceo_registration_enabled'))
        {
            abort(403, 'Cadastro do CEO está desativado.');
        }

        $secret = config('admin.ceo_registration_secret');

        if(! $secret)
        {
            abort(403, 'Cadastro do CEO não está configurado corretamente.');
        }

        $requestKey = $request->query('key') ?? $request->input('key');

        if(! hash_equals((string) $secret, (string) $requestKey))
        {
            abort(403, 'Chave de acesso inválida para cadastro do CEO.');
        }

        if(Corretor::where('role', 'ceo')->exists())
        {
            abort(403, 'O CEO já foi cadastrado. O cadastro do CEO não pode ser realizado novamente.');
        }

        return $next($request);
    }
}
