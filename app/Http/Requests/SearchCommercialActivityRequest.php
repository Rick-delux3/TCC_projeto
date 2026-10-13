<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SearchCommercialActivityRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'q.required' => 'Digite uma atividade ou código para pesquisar.',
            'q.string' => 'Informe um texto válido para pesquisar.',
            'q.min' => 'Digite pelo menos dois caracteres.',
            'q.max' => 'Use até 100 caracteres na pesquisa.',
        ];
    }
}
