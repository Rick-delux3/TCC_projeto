<?php

namespace App\Http\Controllers;

use App\Models\Admins;
use Illuminate\Http\Request;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;

class AdminsRegistrationController extends Controller
{
     public function showRegistrationForm()
    {
        return view('Admin-Register');
    }

    public function store(Request $request){
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:admins,name',
            'email' => 'required|string|lowercase|email:rfc,dns|max:255|unique:admins,email',
            'password' => 'required|string|min:6|confirmed',
            'cpf' => 'required|string|regex:/^\d{11}$/|unique:admins,cpf',

        ]);

        $admin = Admins::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'cpf' => $data['cpf'],
        ]);



    }

}
