<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePublicLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => $this->limparTexto($this->nome),
            'email' => mb_strtolower(trim((string) $this->email)),
            'cpf' => $this->somenteNumeros($this->cpf),
            'telefone' => $this->somenteNumeros($this->telefone),
            'estado_civil' => mb_strtolower(trim((string) $this->estado_civil)),
            'conjuge_nome' => $this->limparTexto($this->conjuge_nome),
            'conjuge_cpf' => $this->somenteNumeros($this->conjuge_cpf),
            'valor_aluguel' => $this->normalizarDinheiro($this->valor_aluguel),
            'outras_despesas' => $this->normalizarDinheiro($this->outras_despesas),
            'cidade_imovel' => $this->limparTexto($this->cidade_imovel),
            'estado' => mb_strtoupper(trim((string) $this->estado)),
            'responsavel_preenchimento' => $this->limparTexto($this->responsavel_preenchimento),
        ]);
    }

    public function rules(): array
    {
        return [
            'nome' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],

            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
            ],

            'cpf' => [
                'required',
                'string',
                'size:11',
                'regex:/^\d{11}$/',
            ],

            'telefone' => [
                'required',
                'string',
                'min:10',
                'max:11',
                'regex:/^\d{10,11}$/',
            ],

            'estado_civil' => [
                'required',
                'string',
                'in:solteiro,casado,uniao_estavel,divorciado,viuvo',
            ],

            'conjuge_nome' => [
                'nullable',
                'required_if:estado_civil,casado,uniao_estavel',
                'string',
                'min:3',
                'max:255',
            ],

            'conjuge_cpf' => [
                'nullable',
                'required_if:estado_civil,casado,uniao_estavel',
                'string',
                'size:11',
                'regex:/^\d{11}$/',
            ],

            'valor_aluguel' => [
                'required',
                'numeric',
                'min:1',
                'max:999999.99',
            ],

            'outras_despesas' => [
                'nullable',
                'numeric',
                'min:0',
                'max:999999.99',
            ],

            'cidade_imovel' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],

            'estado' => [
                'nullable',
                'string',
                'size:2',
            ],

            'responsavel_preenchimento' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],

            // Campo invisível contra bots.
            'website' => [
                'nullable',
                'size:0',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('cpf') && !$this->cpfValido($this->cpf)) {
                    $validator->errors()->add('cpf', 'O CPF informado é inválido.');
                }

                if ($this->filled('conjuge_cpf') && !$this->cpfValido($this->conjuge_cpf)) {
                    $validator->errors()->add('conjuge_cpf', 'O CPF do cônjuge é inválido.');
                }

                if (
                    $this->filled('cpf') &&
                    $this->filled('conjuge_cpf') &&
                    $this->cpf === $this->conjuge_cpf
                ) {
                    $validator->errors()->add('conjuge_cpf', 'O CPF do cônjuge não pode ser igual ao CPF do titular.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome do lead.',
            'nome.min' => 'O nome precisa ter pelo menos :min caracteres.',

            'email.required' => 'Informe o e-mail.',
            'email.email' => 'Informe um e-mail válido.',

            'cpf.required' => 'Informe o CPF.',
            'cpf.size' => 'O CPF deve conter 11 números.',

            'telefone.required' => 'Informe o telefone.',
            'telefone.min' => 'O telefone deve conter DDD e número.',
            'telefone.max' => 'O telefone deve conter no máximo 11 números.',

            'estado_civil.required' => 'Informe o estado civil.',
            'estado_civil.in' => 'Informe um estado civil válido.',

            'conjuge_nome.required_if' => 'Informe o nome do cônjuge.',
            'conjuge_cpf.required_if' => 'Informe o CPF do cônjuge.',
            'conjuge_cpf.size' => 'O CPF do cônjuge deve conter 11 números.',

            'valor_aluguel.required' => 'Informe o valor do aluguel.',
            'valor_aluguel.numeric' => 'Informe um valor de aluguel válido.',
            'valor_aluguel.min' => 'O valor do aluguel deve ser maior que zero.',

            'outras_despesas.numeric' => 'Informe um valor válido para outras despesas.',

            'cidade_imovel.required' => 'Informe a cidade do imóvel pretendido.',

            'responsavel_preenchimento.required' => 'Informe o nome do responsável pelo preenchimento.',

            'website.size' => 'Requisição inválida.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nome' => 'nome',
            'email' => 'e-mail',
            'cpf' => 'CPF',
            'telefone' => 'telefone',
            'estado_civil' => 'estado civil',
            'conjuge_nome' => 'nome do cônjuge',
            'conjuge_cpf' => 'CPF do cônjuge',
            'valor_aluguel' => 'valor do aluguel',
            'outras_despesas' => 'outras despesas',
            'cidade_imovel' => 'cidade do imóvel pretendido',
            'responsavel_preenchimento' => 'responsável pelo preenchimento',
        ];
    }

    private function somenteNumeros($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return preg_replace('/\D/', '', (string) $valor);
    }

    private function limparTexto($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', (string) $valor));
    }

    private function normalizarDinheiro($valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

       $valor = (string) $valor;

       $valor = preg_replace('/[^\d,\.]/u', '', $valor);

       if(str_contains($valor, ',')){
            $valor = str_replace('.', '', $valor);
            $valor = str_replace(',', '.', $valor);
       }

        return is_numeric($valor) ? $valor : null;
    }

    private function cpfValido(?string $cpf): bool
    {
        if (!$cpf || strlen($cpf) !== 11) {
            return false;
        }

        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $soma = 0;

            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $cpf[$i] * (($t + 1) - $i);
            }

            $digito = ((10 * $soma) % 11) % 10;

            if ((int) $cpf[$t] !== $digito) {
                return false;
            }
        }

        return true;
    }
}