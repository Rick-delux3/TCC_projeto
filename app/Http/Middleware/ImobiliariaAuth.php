<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ImobiliariaAuth
{
    public function handle($request, Closure $next)
    {
        if (!session()->has('company_id')) {
            return redirect('/company-login');
        }

        return $next($request);
    }
}
