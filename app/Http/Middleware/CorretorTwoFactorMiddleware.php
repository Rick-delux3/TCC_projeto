<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;


class CorretorTwoFactorMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if(! $admin) {
            return redirect()->route('admin.login');
        }

        if(!$admin->isActive())
        {
            Auth::guard('admin')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route(
                $admin->isCeo() ? 'admin.ceo.login' : 'admin.login'
            );
        }

        if ($admin->hasVerifiedFirstLogin()) 
        {
            return $next($request);
        }

        if(session('admin_2fa_passed') === true) {
            return $next($request);
        }

        return redirect()->route('admin.2fa.form');
    }
}
