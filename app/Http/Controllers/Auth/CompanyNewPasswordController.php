<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Imobiliaria;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class CompanyNewPasswordController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.company-reset-password', ['request' => $request]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::broker('companies')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Imobiliaria $company) use ($request) {
                DB::transaction(function () use ($company, $request) {
                    $password = Hash::make($request->password);

                    $company->forceFill(['password' => $password])->save();
                    $company->users()->update(['password' => $password]);
                });

                event(new PasswordReset($company));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('empresa.login')->with('success', __($status))
            : back()->withInput($request->only('email'))
                ->withErrors(['email' => __($status)]);
    }
}
