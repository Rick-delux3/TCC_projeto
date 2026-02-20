<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\TwoFactorCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class CompanyAuthController extends Controller
{
    public function showLoginForm()
    {
        return view('company-login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $company = Company::where('email', $data['email'])->first();

        if (!$company || !Hash::check($data['password'], $company->password)) {
            return back()->withErrors(['email' => 'E-mail ou senha incorretos.']);
        }

        $user = User::where('company_id', $company->id)->first();

        if (!$user) {
            return back()->withErrors(['email' => 'Usuario admin nao encontrado.']);
        }

        session(['company_id' => $company->id]);
        Auth::login($user);

        // Keep only one active code per user.
        TwoFactorCode::where('user_id', $user->id)->delete();

        $code = rand(100000, 999999);

        TwoFactorCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::send('emails.2fa-code', ['code' => $code], function ($message) use ($request) {
            $message->to($request->email)->subject('Seu codigo de verificacao');
        });

        session()->forget('2fa_passed');

        // Reset old 2FA throttling state for this login challenge.
        RateLimiter::clear('2fa:verify:' . $user->id . ':' . $request->ip());
        RateLimiter::clear('2fa:resend:' . $user->id . ':' . $request->ip());
        RateLimiter::clear('2fa:resend-cooldown:' . $user->id . ':' . $request->ip());

        return redirect()->route('2fa')->with('success', 'Codigo enviado ao seu e-mail.');
    }

    public function logout()
    {
        Auth::logout();
        session()->forget('company_id');

        return redirect()->route('empresa.login')->with('success', 'Logout realizado com sucesso.');
    }
}
