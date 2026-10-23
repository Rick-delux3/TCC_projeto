<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;

class RedirectAuthenticatedCompany extends RedirectIfAuthenticated
{
    protected function redirectTo(Request $request): ?string
    {
        if (config('branding.active') === 'tcc') {
            return route('company.dashboard');
        }

        return parent::redirectTo($request);
    }
}
