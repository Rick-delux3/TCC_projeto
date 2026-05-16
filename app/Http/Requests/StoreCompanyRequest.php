<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza os dados antes da validação.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->limparTexto($this->name),
            'email' => mb_strtolower(trim((string) $this->email)),
            'phone' => $this->somenteNumeros($this->phone),
            'cnpj' => $this->somenteNumeros($this->cnpj),
            'city' => $this->limparTexto($this->city),
            'state' => mb_strtoupper(trim((string) $this->state)),
            'lead_form_active' => $this->boolean('lead_form_active', true),
        ]);
    }

    public function rules(): array
    {
        return [
            // Campo invisível contra bots, se você quiser usar no form.
            'website' => ['nullable', 'size:0'],

            'name' => [
                'required',
                'string',
                'min:3',
                'max:255',
                Rule::unique('companies', 'name'),
            ],

            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('companies', 'email'),
            ],

            'phone' => [
                'required',
                'string',
                'min:10',
                'max:11',
                'regex:/^\d{10,11}$/',
                Rule::unique('companies', 'phone'),
            ],

            'cnpj' => [
                'required',
                'string',
                'size:14',
                'regex:/^\d{14}$/',
                Rule::unique('companies', 'cnpj'),
            ],

            'password' => [
                'required',
                'confirmed',
                'max:72',
                Password::min(8)
                    ->letters()
                    ->numbers(),
            ],

            'city' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],

            'state' => [
                'required',
                'string',
                'size:2',
                Rule::in([
                    'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO',
                    'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI',
                    'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
                ]),
            ],

            /**
             * Pode existir no cadastro, mas não precisa ficar visível.
             * Se não vier no formulário, será tratado como true.
             */
            'lead_form_active' => [
                'nullable',
                'boolean',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('cnpj') && !$this->cnpjValido($this->cnpj)) {
                    $validator->errors()->add('cnpj', 'O CNPJ informado é inválido.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'website.size' => 'Requisição inválida.',

            'name.required' => 'Informe o nome da imobiliária.',
            'name.min' => 'O nome da imobiliária deve ter pelo menos 3 caracteres.',
            'name.unique' => 'Já existe uma imobiliária cadastrada com esse nome.',

            'email.required' => 'Informe o e-mail da imobiliária.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Já existe uma imobiliária cadastrada com esse e-mail.',

            'phone.required' => 'Informe o telefone da imobiliária.',
            'phone.min' => 'O telefone deve ter pelo menos 10 dígitos.',
            'phone.max' => 'O telefone deve ter no máximo 11 dígitos.',
            'phone.regex' => 'O telefone deve conter apenas números.',
            'phone.unique' => 'Já existe uma imobiliária cadastrada com esse telefone.',

            'cnpj.required' => 'Informe o CNPJ da imobiliária.',
            'cnpj.size' => 'O CNPJ deve ter 14 dígitos.',
            'cnpj.regex' => 'O CNPJ deve conter apenas números.',
            'cnpj.unique' => 'Já existe uma imobiliária cadastrada com esse CNPJ.',

            'password.required' => 'Informe uma senha.',
            'password.confirmed' => 'A confirmação de senha não confere.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.max' => 'A senha deve ter no máximo 72 caracteres.',

            'city.required' => 'Informe a cidade.',
            'state.required' => 'Informe o estado.',
            'state.size' => 'O estado deve ter 2 letras.',
            'state.in' => 'Informe uma UF válida.',
        ];
    }

    private function somenteNumeros($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return only_numbers((string) $valor);
    }

    private function limparTexto($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', (string) $valor));
    }

    private function cnpjValido(?string $cnpj): bool
    {
        if (!$cnpj || strlen($cnpj) !== 14) {
            return false;
        }

        // Rejeita CNPJs com todos os dígitos iguais.
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $pesosPrimeiroDigito = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesosSegundoDigito = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $soma = 0;

        for ($i = 0; $i < 12; $i++) {
            $soma += (int) $cnpj[$i] * $pesosPrimeiroDigito[$i];
        }

        $resto = $soma % 11;
        $primeiroDigito = $resto < 2 ? 0 : 11 - $resto;

        if ((int) $cnpj[12] !== $primeiroDigito) {
            return false;
        }

        $soma = 0;

        for ($i = 0; $i < 13; $i++) {
            $soma += (int) $cnpj[$i] * $pesosSegundoDigito[$i];
        }

        $resto = $soma % 11;
        $segundoDigito = $resto < 2 ? 0 : 11 - $resto;

        return (int) $cnpj[13] === $segundoDigito;
    }
}