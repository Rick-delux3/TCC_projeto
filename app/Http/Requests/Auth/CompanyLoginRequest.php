<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class CompanyLoginRequest extends FormRequest
{
    protected $redirectRoute = 'empresa.login';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Informe o e-mail da imobiliária.',
            'email.string' => 'Informe um e-mail válido.',
            'email.email' => 'Informe um e-mail válido.',
            'email.max' => 'O e-mail deve ter no máximo 255 caracteres.',
            'password.required' => 'Informe a senha.',
            'password.string' => 'Informe uma senha válida.',
            'password.max' => 'A senha deve ter no máximo 72 caracteres.',
        ];
    }
}
