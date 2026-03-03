<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminTwoFactorMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::guard('admin')->check() && !session()->has('admin_2fa_passed')) {
            return redirect()->route('admin.2fa.form');
        }

        return $next($request);
    }
}
