<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TwoFactorMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::guard('web')->check() && $request->session()->get('2fa_passed') !== true) {
            return redirect('/2fa');
        }

        return $next($request);
    }
}
