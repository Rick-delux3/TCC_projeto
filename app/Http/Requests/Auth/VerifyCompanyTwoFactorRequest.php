<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCompanyTwoFactorRequest extends FormRequest
{
    protected $redirectRoute = '2fa';

    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'digits:6'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Informe o código de verificação.',
            'code.digits' => 'O código de verificação deve conter seis dígitos.',
        ];
    }
}
