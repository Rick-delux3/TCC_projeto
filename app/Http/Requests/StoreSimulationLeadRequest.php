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
            'tel' => $this->somenteNumeros($this->tel),

            'estado_civil' => mb_strtolower(trim((string) $this->estado_civil)),
            'conjuge_nome' => $this->limparTexto($this->conjuge_nome),
            'conjuge_cpf' => $this->somenteNumeros($this->conjuge_cpf),

            'valor_aluguel' => $this->normalizarDinheiro($this->valor_aluguel),
            'valor_agua' => $this->normalizarDinheiro($this->valor_agua),
            'valor_luz' => $this->normalizarDinheiro($this->valor_luz),
            'valor_gas' => $this->normalizarDinheiro($this->valor_gas),
            'valor_iptu' => $this->normalizarDinheiro($this->valor_iptu),
            'valor_condominio' => $this->normalizarDinheiro($this->valor_condominio),
            'outras_despesas' => $this->normalizarDinheiro($this->outras_despesas),

            'cep' => $this->somenteNumeros($this->cep),
            'logradouro' => $this->limparTexto($this->logradouro),
            'numero' => $this->limparTexto($this->numero),
            'complemento' => $this->limparTexto($this->complemento),
            'bairro' => $this->limparTexto($this->bairro),
            'cidade_imovel' => $this->limparTexto($this->cidade_imovel),
            'estado' => mb_strtoupper(trim((string) $this->estado)),

            'responsavel_preenchimento' => $this->limparTexto($this->responsavel_preenchimento),
            'telefone_responsavel' => $this->somenteNumeros($this->telefone_responsavel),


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

            'aceite_termos' => ['accepted'],

            'nome' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'tel' => ['required', 'string', 'min:10', 'max:11'],

            // CPF pode ser obrigatório dependendo do perfil, mas aqui deixamos flexível.
            'cpf' => ['nullable', 'string', 'size:11', 'regex:/^\d{11}$/'],
            'cnpj' => ['nullable', 'string', 'size:14', 'regex:/^\d{14}$/'],

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
            'valor_agua' => ['nullable', 'numeric', 'min:0', 'max:99999.999'],
            'valor_luz' => ['nullable', 'numeric', 'min:0', 'max:99999.999'],
            'valor_gas' => ['nullable', 'numeric', 'min:0', 'max:99999.999'],
            'valor_iptu' => ['nullable', 'numeric', 'min:0', 'max:99999.999'],
            'valor_condominio' => ['nullable', 'numeric', 'min:0', 'max:99999.999'],
            'outras_despesas' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            'cep' => ['required', 'string', 'size:8', 'regex:/^\d{8}$/'],
            'logradouro' => ['required', 'string', 'max:255'],
            'numero' => ['required', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:100'],
            'bairro' => ['required', 'string', 'max:100'],
            'cidade_imovel' => ['required', 'string', 'max:100'],
            'estado' => ['required', 'string', 'size:2'],

            'responsavel_preenchimento' => ['nullable', 'string', 'min:3', 'max:255'],
            'telefone_responsavel' => ['nullable', 'string', 'min:10', 'max:11'],

            'nome_imobiliaria_informada' => ['nullable', 'string', 'min:3', 'max:255'],
            'cnpj_imobiliaria_informada' => ['nullable', 'string', 'size:14'],

            'nome_locador' => ['nullable', 'string', 'max:255'],
            'telefone_locador' => ['nullable', 'string', 'min:10', 'max:11'],
            'email_locador' => ['nullable', 'email:rfc', 'max:255'],

            'observacoes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array 
    {
        return [
            /*
        |--------------------------------------------------------------------------
        | Proteção contra bots / termos
        |--------------------------------------------------------------------------
        */

        'website.size' => 'Falha na validação do formulário. Recarregue a página e tente novamente.',

        'aceite_termos.accepted' => 'Você precisa aceitar os termos para continuar.',


        /*
        |--------------------------------------------------------------------------
        | Dados principais do solicitante
        |--------------------------------------------------------------------------
        */

        'nome.required' => 'Informe o nome completo.',
        'nome.string' => 'O nome deve ser um texto válido.',
        'nome.min' => 'O nome deve ter pelo menos :min caracteres.',
        'nome.max' => 'O nome não pode ter mais de :max caracteres.',

        'email.required' => 'Informe o e-mail.',
        'email.string' => 'O e-mail deve ser um texto válido.',
        'email.email' => 'Informe um e-mail válido.',
        'email.max' => 'O e-mail não pode ter mais de :max caracteres.',

        'tel.required' => 'Informe o telefone.',
        'tel.string' => 'O telefone deve ser um texto válido.',
        'tel.min' => 'O telefone deve ter pelo menos :min dígitos.',
        'tel.max' => 'O telefone não pode ter mais de :max dígitos.',

        'cpf.string' => 'O CPF deve ser um texto válido.',
        'cpf.size' => 'O CPF deve conter exatamente 11 dígitos.',
        'cpf.regex' => 'Informe um CPF válido, contendo apenas números.',

        'cnpj.string' => 'O CNPJ deve ser um texto válido.',
        'cnpj.size' => 'O CNPJ deve conter exatamente 14 dígitos.',
        'cnpj.regex' => 'Informe um CNPJ válido, contendo apenas números.',


        /*
        |--------------------------------------------------------------------------
        | Estado civil e cônjuge
        |--------------------------------------------------------------------------
        */

        'estado_civil.string' => 'O estado civil deve ser um texto válido.',
        'estado_civil.in' => 'Selecione um estado civil válido.',

        'conjuge_nome.required_if' => 'Informe o nome do cônjuge.',
        'conjuge_nome.string' => 'O nome do cônjuge deve ser um texto válido.',
        'conjuge_nome.min' => 'O nome do cônjuge deve ter pelo menos :min caracteres.',
        'conjuge_nome.max' => 'O nome do cônjuge não pode ter mais de :max caracteres.',

        'conjuge_cpf.required_if' => 'Informe o CPF do cônjuge.',
        'conjuge_cpf.string' => 'O CPF do cônjuge deve ser um texto válido.',
        'conjuge_cpf.size' => 'O CPF do cônjuge deve conter exatamente 11 dígitos.',
        'conjuge_cpf.regex' => 'Informe um CPF válido para o cônjuge, contendo apenas números.',


        /*
        |--------------------------------------------------------------------------
        | Valores do aluguel e despesas
        |--------------------------------------------------------------------------
        */

        'valor_aluguel.required' => 'Informe o valor do aluguel.',
        'valor_aluguel.numeric' => 'O valor do aluguel deve ser um número válido.',
        'valor_aluguel.min' => 'O valor do aluguel deve ser maior que zero.',
        'valor_aluguel.max' => 'O valor do aluguel informado é muito alto.',

        'valor_agua.numeric' => 'O valor da água deve ser um número válido.',
        'valor_agua.min' => 'O valor da água não pode ser negativo.',
        'valor_agua.max' => 'O valor da água informado é muito alto.',

        'valor_luz.numeric' => 'O valor da luz deve ser um número válido.',
        'valor_luz.min' => 'O valor da luz não pode ser negativo.',
        'valor_luz.max' => 'O valor da luz informado é muito alto.',

        'valor_gas.numeric' => 'O valor do gás deve ser um número válido.',
        'valor_gas.min' => 'O valor do gás não pode ser negativo.',
        'valor_gas.max' => 'O valor do gás informado é muito alto.',

        'valor_iptu.numeric' => 'O valor do IPTU deve ser um número válido.',
        'valor_iptu.min' => 'O valor do IPTU não pode ser negativo.',
        'valor_iptu.max' => 'O valor do IPTU informado é muito alto.',

        'valor_condominio.numeric' => 'O valor do condomínio deve ser um número válido.',
        'valor_condominio.min' => 'O valor do condomínio não pode ser negativo.',
        'valor_condominio.max' => 'O valor do condomínio informado é muito alto.',

        'outras_despesas.numeric' => 'O valor de outras despesas deve ser um número válido.',
        'outras_despesas.min' => 'O valor de outras despesas não pode ser negativo.',
        'outras_despesas.max' => 'O valor de outras despesas informado é muito alto.',


        /*
        |--------------------------------------------------------------------------
        | Endereço do imóvel
        |--------------------------------------------------------------------------
        */

        'cep.required' => 'Informe o CEP do imóvel.',
        'cep.string' => 'O CEP deve ser um texto válido.',
        'cep.size' => 'O CEP deve conter exatamente 8 dígitos.',
        'cep.regex' => 'Informe um CEP válido, contendo apenas números.',

        'logradouro.required' => 'Informe o logradouro do imóvel.',
        'logradouro.string' => 'O logradouro deve ser um texto válido.',
        'logradouro.max' => 'O logradouro não pode ter mais de :max caracteres.',

        'numero.required' => 'Informe o número do imóvel.',
        'numero.string' => 'O número do imóvel deve ser um texto válido.',
        'numero.max' => 'O número do imóvel não pode ter mais de :max caracteres.',

        'complemento.string' => 'O complemento deve ser um texto válido.',
        'complemento.max' => 'O complemento não pode ter mais de :max caracteres.',

        'bairro.required' => 'Informe o bairro do imóvel.',
        'bairro.string' => 'O bairro deve ser um texto válido.',
        'bairro.max' => 'O bairro não pode ter mais de :max caracteres.',

        'cidade_imovel.required' => 'Informe a cidade do imóvel.',
        'cidade_imovel.string' => 'A cidade do imóvel deve ser um texto válido.',
        'cidade_imovel.max' => 'A cidade do imóvel não pode ter mais de :max caracteres.',

        'estado.required' => 'Informe o estado do imóvel.',
        'estado.string' => 'O estado deve ser um texto válido.',
        'estado.size' => 'Informe o estado usando a sigla UF com 2 letras. Exemplo: SP.',


        /*
        |--------------------------------------------------------------------------
        | Responsável pelo preenchimento
        |--------------------------------------------------------------------------
        */

        'responsavel_preenchimento.string' => 'O nome do responsável pelo preenchimento deve ser um texto válido.',
        'responsavel_preenchimento.min' => 'O nome do responsável deve ter pelo menos :min caracteres.',
        'responsavel_preenchimento.max' => 'O nome do responsável não pode ter mais de :max caracteres.',

        'telefone_responsavel.string' => 'O telefone do responsável deve ser um texto válido.',
        'telefone_responsavel.min' => 'O telefone do responsável deve ter pelo menos :min dígitos.',
        'telefone_responsavel.max' => 'O telefone do responsável não pode ter mais de :max dígitos.',


        /*
        |--------------------------------------------------------------------------
        | Imobiliária informada
        |--------------------------------------------------------------------------
        */

        'nome_imobiliaria_informada.string' => 'O nome da imobiliária deve ser um texto válido.',
        'nome_imobiliaria_informada.min' => 'O nome da imobiliária deve ter pelo menos :min caracteres.',
        'nome_imobiliaria_informada.max' => 'O nome da imobiliária não pode ter mais de :max caracteres.',

        'cnpj_imobiliaria_informada.string' => 'O CNPJ da imobiliária deve ser um texto válido.',
        'cnpj_imobiliaria_informada.size' => 'O CNPJ da imobiliária deve conter exatamente 14 dígitos.',


        /*
        |--------------------------------------------------------------------------
        | Dados do locador
        |--------------------------------------------------------------------------
        */

        'nome_locador.string' => 'O nome do locador deve ser um texto válido.',
        'nome_locador.max' => 'O nome do locador não pode ter mais de :max caracteres.',

        'telefone_locador.string' => 'O telefone do locador deve ser um texto válido.',
        'telefone_locador.min' => 'O telefone do locador deve ter pelo menos :min dígitos.',
        'telefone_locador.max' => 'O telefone do locador não pode ter mais de :max dígitos.',

        'email_locador.email' => 'Informe um e-mail válido para o locador.',
        'email_locador.max' => 'O e-mail do locador não pode ter mais de :max caracteres.',


        /*
        |--------------------------------------------------------------------------
        | Observações
        |--------------------------------------------------------------------------
        */

        'observacoes.string' => 'As observações devem ser um texto válido.',
        'observacoes.max' => 'As observações não podem ter mais de :max caracteres.',
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