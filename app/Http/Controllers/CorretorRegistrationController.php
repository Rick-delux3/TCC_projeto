<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureCeoRegistrationIsAuthorized;
use App\Http\Requests\AuthorizeCeoRegistrationRequest;
use App\Models\Corretor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class CorretorRegistrationController extends Controller
{
    public function showCeoRegistrationAccessForm(Request $request): View|RedirectResponse
    {
        if ($request->query->has('key')) {
            return redirect()->route('admin.ceo.register.access');
        }

        return view('corretor.admin-ceo-register-access');
    }

    public function authorizeCeoRegistration(AuthorizeCeoRegistrationRequest $request): RedirectResponse
    {
        $request->session()->regenerate();
        $request->session()->put(
            EnsureCeoRegistrationIsAuthorized::SESSION_KEY,
            now()->getTimestamp()
        );

        return redirect()->route('admin.ceo.register.form');
    }

    public function showCeoRegistrationForm(): View
    {
        return view('corretor.admin-ceo-register');
    }

    public function storeCeo(Request $request): RedirectResponse
    {
        $request->merge([
            'name' => trim(preg_replace('/\s+/', ' ', (string) $request->name)),
            'email' => mb_strtolower(trim((string) $request->email)),
            'cpf' => preg_replace('/\D/', '', (string) $request->cpf),
        ]);

        $data = $request->validate([
            'website' => ['nullable', 'size:0'],
            'name' => ['required', 'string', 'max:255', 'unique:corretores,name'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email:rfc',
                'max:255',
                'unique:corretores,email',
            ],
            'password' => [
                'required',
                'string',
                'confirmed',
                'max:72',
                Password::min(8)->letters()->numbers(),
            ],
            'cpf' => [
                'bail',
                'required',
                'string',
                'regex:/^\d{11}$/',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! $this->cpfValido((string) $value)) {
                        $fail('Informe um CPF válido.');
                    }
                },
                'unique:corretores,cpf',
            ],
        ], [
            'website.size' => 'Requisição inválida.',
            'name.required' => 'Informe o nome do CEO.',

            'email.required' => 'O campo e-mail é obrigatório.',
            'email.email' => 'O campo e-mail deve ser um endereço de e-mail válido.',
            'email.unique' => 'O e-mail informado já está em uso.',

            'password.required' => 'O campo senha é obrigatório.',
            'password.confirmed' => 'A confirmação da senha não corresponde.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.max' => 'A senha deve ter no máximo 72 caracteres.',
            'password.letters' => 'A senha deve conter pelo menos uma letra.',
            'password.numbers' => 'A senha deve conter pelo menos um número.',

            'cpf.required' => 'O campo CPF é obrigatório.',
            'cpf.regex' => 'O campo CPF deve conter exatamente 11 dígitos numéricos.',
            'cpf.unique' => 'O CPF informado já está em uso.',
        ]);

        DB::transaction(function () use ($data) {
            $ceo = Corretor::query()
                ->where('role', Corretor::ROLE_CEO)
                ->lockForUpdate()
                ->first();

            if ($ceo) {
                abort(403, 'O CEO já foi cadastrado. O cadastro inicial está fechado.');
            }

            Corretor::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'cpf' => $data['cpf'],
                'role' => Corretor::ROLE_CEO,
                'active' => true,
                'first_login_verified_at' => null,
                'first_login_code_sent_at' => null,
                'last_login_at' => null,
            ]);
        });

        $request->session()->forget(EnsureCeoRegistrationIsAuthorized::SESSION_KEY);

        return redirect()->route('admin.ceo.login')->with(
            'success',
            'Cadastro realizado. Faça login para continuar.'
        );
    }

    private function cpfValido(string $cpf): bool
    {
        if (
            strlen($cpf) !== 11
            || preg_match('/^(\d)\1{10}$/', $cpf) === 1
        ) {
            return false;
        }

        for ($position = 9; $position < 11; $position++) {
            $sum = 0;

            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $cpf[$index] * (($position + 1) - $index);
            }

            $digit = (10 * $sum) % 11;
            $digit = $digit === 10 ? 0 : $digit;

            if ((int) $cpf[$position] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
