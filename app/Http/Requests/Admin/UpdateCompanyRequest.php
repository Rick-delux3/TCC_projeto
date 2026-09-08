<?php

namespace App\Http\Requests\Admin;

use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Rules\CpfOrCnpj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $corretor = $this->user('admin');

        return $corretor instanceof Corretor
            && Gate::forUser($corretor)
                ->allows('update-real-estate-company');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->normalizeText($this->input('name')),
            'email' => mb_strtolower(
                trim((string) $this->input('email'))
            ),
            'phone' => $this->digitsOnly($this->input('phone')),
            'cnpj' => CpfOrCnpj::normalize($this->input('cnpj')),
            'cep' => $this->digitsOnly($this->input('cep')),
            'city' => $this->normalizeText($this->input('city')),
            'state' => mb_strtoupper(
                trim((string) $this->input('state'))
            ),
            'lead_form_active' => $this->exists('lead_form_active')
                ? $this->boolean('lead_form_active')
                : null,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $company = $this->route('company');

        $companyId = $company instanceof Imobiliaria ? $company->getKey() : null;

        $primaryUserId = $company instanceof Imobiliaria
            ? $company->usuarios()
                ->orderBy('id')
                ->value('id')
            : null;

        $userEmailRule = Rule::unique('users', 'email');

        if ($primaryUserId !== null) {
            $userEmailRule->ignore($primaryUserId);
        }

        return [
            'name' => [
                'bail',
                'required',
                'string',
                'max:255',
                Rule::unique('imobiliarias', 'name')
                    ->ignore($companyId),
            ],

            'email' => [
                'bail',
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('imobiliarias', 'email')
                    ->ignore($companyId),
                $userEmailRule,
            ],

            'phone' => [
                'bail',
                'required',
                'string',
                'regex:/^\d{10,11}$/',
                Rule::unique('imobiliarias', 'phone')
                    ->ignore($companyId),
            ],

            'cnpj' => [
                'bail',
                'required',
                'string',
                new CpfOrCnpj,
                Rule::unique('imobiliarias', 'cnpj')
                    ->ignore($companyId),
            ],

            'cep' => [
                'bail',
                'required',
                'string',
                'regex:/^\d{8}$/',
            ],

            'city' => [
                'bail',
                'required',
                'string',
                'min:2',
                'max:100',
            ],

            'state' => [
                'bail',
                'required',
                'string',
                'size:2',
                Rule::in([
                    'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES',
                    'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR',
                    'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC',
                    'SP', 'SE', 'TO',
                ]),
            ],

            'lead_form_active' => [
                'required',
                'boolean',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da imobiliária.',
            'name.unique' => 'Já existe uma imobiliária com esse nome.',

            'email.required' => 'Informe o e-mail da imobiliária.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Este e-mail já está sendo utilizado.',

            'phone.required' => 'Informe o telefone da imobiliária.',
            'phone.regex' => 'O telefone deve conter 10 ou 11 números.',
            'phone.unique' => 'Este telefone já está sendo utilizado.',

            'cnpj.required' => 'Informe o CPF ou CNPJ da imobiliária.',
            'cnpj.string' => 'Informe um CPF ou CNPJ válido.',
            'cnpj.unique' => 'Este CPF ou CNPJ já está sendo utilizado.',

            'cep.required' => 'Informe o CEP da imobiliária.',
            'cep.regex' => 'O CEP deve conter exatamente 8 números.',

            'city.required' => 'Informe a cidade.',
            'state.required' => 'Informe a UF.',
            'state.size' => 'A UF deve conter duas letras.',
            'state.in' => 'Informe uma UF válida.',

            'lead_form_active.required' => 'Informe o status do formulário.',
            'lead_form_active.boolean' => 'O status informado é inválido.',
        ];
    }

    private function digitsOnly(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return preg_replace('/\D+/', '', (string) $value);
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim(
            preg_replace('/\s+/u', ' ', (string) $value)
                ?? (string) $value
        );
    }
}
