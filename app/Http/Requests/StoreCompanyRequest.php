<?php

namespace App\Http\Requests;

use App\Services\CompanyTagService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;
use App\Services\CepService;

class StoreCompanyRequest extends FormRequest
{

    private ?array $resolvedCep = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza os dados antes da validação.
     */
    protected function prepareForValidation(): void
    {
        $companyTags = app(CompanyTagService::class);

        $cep = $this->somenteNumeros($this->input('cep'));

        if(is_string($cep) && preg_match('/^\d{8}$/', $cep) === 1) {
            $this->resolvedCep = app(CepService::class)->find($cep);
        }

        $city = $this->resolvedCep['cidade'] ?? $this->input('city');

        $state = $this->resolvedCep['estado'] ?? $this->input('state');

        $normalizedData = [
            'email' => mb_strtolower(trim((string) $this->email)),
            'phone' => $this->somenteNumeros($this->phone),
            'cnpj' => $this->somenteNumeros($this->cnpj),
            'cep' => $cep,
            'city' => $this->limparTexto($city),
            'state' => mb_strtoupper(trim((string) $state)),
            'lead_form_active' => $this->boolean('lead_form_active', true),
        ];

        if ($this->has('leadlovers_tag_id')) {
            $normalizedData['leadlovers_tag_id'] = $this->limparTexto($this->input('leadlovers_tag_id'));
        }

        if ($this->has('company_name')) {
            $normalizedData['company_name'] = $companyTags->normalizeCompanyName($this->input('company_name'));
        }

        $this->merge($normalizedData);
    }

    public function rules(): array
    {
        $companyTags = app(CompanyTagService::class);

        $hasAvailableTags = $companyTags->hasAvailableTags();

        $rules = [
            'website' => ['nullable', 'size:0'],

            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('imobiliarias', 'email'),
                Rule::unique('users', 'email'),
            ],

            'phone' => [
                'required',
                'string',
                'min:10',
                'max:11',
                'regex:/^\d{10,11}$/',
                Rule::unique('imobiliarias', 'phone'),
            ],

            'cnpj' => [
                'required',
                'string',
                'size:14',
                'regex:/^\d{14}$/',
                Rule::unique('imobiliarias', 'cnpj'),
            ],

            'cep' => ['bail', 'required', 'string', 'size:8', 'regex:/^\d{8}$/'],

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

        if ($hasAvailableTags) {
            $rules['leadlovers_tag_id'] = [
                'bail',
                'required',
                'integer',
                Rule::exists('lead_lovers_tags', 'leadlovers_tag_id')
                    ->where('active', true),
                Rule::unique('imobiliarias', 'leadlovers_tag_id'),

                function (string $attribute, mixed $value, \Closure $fail) use ($companyTags) {
                    if (! $companyTags->isAvailable((int) $value)) {
                        $fail('A imobiliária selecionada não está mais disponível.');
                    }
                },
            ];

            $rules['company_name'] = ['prohibited'];
        } else {
            $rules['leadlovers_tag_id'] = ['prohibited'];

            $rules['company_name'] = [
                'bail',
                'required',
                'string',
                'max:100',
                Rule::unique('imobiliarias', 'name'),
                Rule::unique('lead_lovers_tags', 'title'),
            ];
        }

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('cnpj') && ! $this->cnpjValido($this->cnpj)) {
                    $validator->errors()->add('cnpj', 'O CNPJ informado é inválido.');
                }

                $cepHasValidFormat = (is_string($this->cep) && preg_match('/^\d{8}$/', $this->cep) === 1);

                if(
                    $cepHasValidFormat
                    && $this->resolvedCep === null
                ) {
                    $validator->errors()->add(
                        'cep',
                        'CEP não encontrado ou serviço temporariamente indisponível.'
                    );
                }

            },
        ];
    }

    public function messages(): array
    {
        return [
            'website.size' => 'Requisição inválida.',

            'leadlovers_tag_id.required' => 'Informe o nome da imobiliária.',
            'leadlovers_tag_id.integer' => 'A imobiliária selecionada é inválida.',
            'leadlovers_tag_id.exists' => 'A imobiliária selecionada não foi encontrada ou não está disponível.',
            'leadlovers_tag_id.unique' => 'Esta imobiliária já possui cadastro no sistema.',
            'leadlovers_tag_id.prohibited' => 'Não é possível utilizar uma tag neste momento. Atualize a página.',

            'company_name.required' => 'Informe o nome da imobiliária.',

            'company_name.prohibited' => 'Ainda existem imobiliárias disponíveis no campo de seleção.',

            'company_name.string' => 'O nome da imobiliária é inválido.',

            'company_name.max' => 'O nome da imobiliária deve ter no máximo 100 caracteres.',

            'company_name.unique' => 'Já existe uma imobiliária ou tag cadastrada com esse nome.',

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

            'cep.required' => 'Informe o CEP da imobiliária.',
            'cep.string' => 'O CEP deve ser um texto válido.',
            'cep.size' => 'O CEP deve conter exatamente 8 dígitos.',
            'cep.regex' => 'Informe um CEP válido, contendo apenas números.',

            'password.required' => 'Informe uma senha.',
            'password.confirmed' => 'A confirmação de senha não confere.',
            'password.min' => 'A senha deve ter pelo menos 8 caracteres.',
            'password.max' => 'A senha deve ter no máximo 72 caracteres.',
            'password.letters' => 'A senha deve conter pelo menos uma letra.',
            'password.numbers' => 'A senha deve conter pelo menos um número.',

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
        if (! $cnpj || strlen($cnpj) !== 14) {
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
