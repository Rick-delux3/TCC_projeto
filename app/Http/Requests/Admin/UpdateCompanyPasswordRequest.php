<?php

namespace App\Http\Requests\Admin;

use App\Models\Corretor;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

class UpdateCompanyPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $corretor = $this->user('admin');

        return $corretor instanceof Corretor
            && Gate::forUser($corretor)->allows('update-real-estate-company');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array>
     */
    public function rules(): array
    {
        return [
            'password' => ['bail', 'required', 'string', 'max:72', 'confirmed', Password::min(8)->letters()->numbers(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail('A nova senha excede o tamanho permitido. Utilize uma senha mais curta.');
                    }
                },
            ],
            'password_confirmation' => ['required', 'string', 'max:72'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Informe a nova senha.',
            'password.string' => 'A nova senha deve ser um texto válido.',
            'password.min' => 'A nova senha deve ter pelo menos 8 caracteres.',
            'password.max' => 'A nova senha deve ter no máximo 72 caracteres.',
            'password.confirmed' => 'A confirmação da nova senha não confere.',
            'password.letters' => 'A nova senha deve conter letras.',
            'password.numbers' => 'A nova senha deve conter números.',
            'password_confirmation.required' => 'Confirme a nova senha.',
            'password_confirmation.string' => 'A confirmação da senha deve ser um texto válido.',
            'password_confirmation.max' => 'A confirmação deve ter no máximo 72 caracteres.',
        ];
    }
}
