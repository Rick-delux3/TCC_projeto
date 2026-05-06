<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreSimulationLeadRequest extends FormRequest
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

            'nome_imobiliaria_informada' => $this->limparTexto($this->nome_imobiliaria_informada),
            'cnpj_imobiliaria_informada' => $this->somenteNumeros($this->cnpj_imobiliaria_informada),

            'nome_locador' => $this->limparTexto($this->nome_locador),
            'telefone_locador' => $this->somenteNumeros($this->telefone_locador),
            'email_locador' => mb_strtolower(trim((string) $this->email_locador)),
        ]);
    }

    public function rules(): array
    {
        return [
            // Campo invisível contra bots.
            'website' => ['nullable', 'size:0'],

            'nome' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'telefone' => ['required', 'string', 'min:10', 'max:11'],

            // CPF pode ser obrigatório dependendo do perfil, mas aqui deixamos flexível.
            'cpf' => ['nullable', 'string', 'size:11', 'regex:/^\d{11}$/'],

            'estado_civil' => ['nullable', 'string', 'in:solteiro,casado,uniao_estavel,divorciado,viuvo'],

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

            'valor_aluguel' => ['required', 'numeric', 'min:1', 'max:999999.99'],
            'outras_despesas' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            'cidade_imovel' => ['required', 'string', 'min:2', 'max:100'],
            'estado' => ['nullable', 'string', 'size:2'],

            'responsavel_preenchimento' => ['nullable', 'string', 'min:3', 'max:255'],

            'nome_imobiliaria_informada' => ['nullable', 'string', 'max:255'],
            'cnpj_imobiliaria_informada' => ['nullable', 'string', 'size:14'],

            'nome_locador' => ['nullable', 'string', 'max:255'],
            'telefone_locador' => ['nullable', 'string', 'min:10', 'max:11'],
            'email_locador' => ['nullable', 'email:rfc', 'max:255'],

            'observacoes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Valida CPF principal se foi preenchido.
                if ($this->filled('cpf') && !$this->cpfValido($this->cpf)) {
                    $validator->errors()->add('cpf', 'O CPF informado é inválido.');
                }

                // Valida CPF do cônjuge se foi preenchido.
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

        $valor = preg_replace('/[^\d,\.]/u', '', (string) $valor);

        if (str_contains($valor, ',')) {
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