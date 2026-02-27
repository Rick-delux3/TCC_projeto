<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Admins;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class AdminAuthController extends Controller
{
     public function showLoginForm()
    {
        return view('admin-login');
    }

    public function login(Request $request){
        $data = $request->validate([
            'cpf' => 'required|string|regex:/^\d{11}$/|',
            'password' => 'required|string|min:6',
        ]);

        $admin = Admins::where('cpf', $data['cpf'])->first();
    }
}
