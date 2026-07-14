<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CorretorAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $corretor = Auth::guard('admin')->user();

        if (! $corretor) {
            return redirect()->route('admin.login');
        }

        if (! $corretor->isActive()) {
            Auth::guard('admin')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route($corretor->isCeo() ? 'admin.ceo.login' : 'admin.login')
                ->withErrors([
                    'email' => 'Este corretor está inativo. Entre em contato com o CEO da corretora.',
                    'cpf' => 'Este corretor está inativo. Entre em contato com o CEO da corretora.',
                ]);
        }

        return $next($request);
    }
}
