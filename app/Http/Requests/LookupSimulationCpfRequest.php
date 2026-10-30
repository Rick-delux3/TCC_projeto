<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LookupSimulationCpfRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) config('features.cpf_lookup.enabled');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cpf' => ['bail', 'required', 'string', 'size:11', new \App\Rules\CpfOrCnpj],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['cpf' => \App\Rules\CpfOrCnpj::normalize($this->input('cpf'))]);
    }

    public function messages(): array
    {
        return ['cpf.required' => 'Informe o CPF para consultar os dados.', 'cpf.string' => 'Confira o CPF informado.', 'cpf.size' => 'Informe os 11 dígitos do CPF.'];
    }
}
