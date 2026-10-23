<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class AdminPortalAccessController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        abort_unless(config('branding.active') === 'tcc', 404);

        return redirect()->route(
            Auth::guard('admin')->check() ? 'Dashboard-Admin' : 'admin.ceo.login'
        );
    }
}
