<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;


class TwoFactorMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && !session()->has('2fa_passed')) {
            return redirect('/2fa');
        }

        return $next($request);
    }
}
