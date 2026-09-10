<?php

namespace App\Http\Requests;

use App\Enums\TipoLocacao;
use App\Models\Lead;
use App\Rules\CpfOrCnpj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAdminLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin !== null && Gate::forUser($admin)->allows('edit-leads');
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach ($this->all() as $key => $value) {
            if (is_string($value)) {
                $value = trim(preg_replace('/\s+/u', ' ', $value));
                $values[$key] = $value === '' ? null : $value;
            }
        }
        foreach (['cpf', 'cpf_responsavel', 'conjuge_cpf'] as $field) {
            if ($this->exists($field)) {
                $values[$field] = CpfOrCnpj::normalize($this->input($field));
            }
        }
        foreach (['tel', 'responsavel_telefone', 'cep'] as $field) {
            if ($this->exists($field) && is_string($this->input($field))) {
                $values[$field] = preg_replace('/\D/', '', $this->input($field)) ?: null;
            }
        }
        $values['lead_context_id'] = $this->route('lead')->getKey();
        $this->merge($values);
    }

    public function rules(): array
    {
        /** @var Lead $lead */
        $lead = $this->route('lead');
        $document = $this->input('cpf', $lead->lead_empresa?->cnpj ?? $lead->cpf);
        $company = is_string($document) && preg_match('/^\d{14}$/D', $document) === 1;
        $status = $this->input('estado_civil', $lead->estado_civil);
        $spouse = in_array($status, ['casado', 'uniao_estavel', 'divorciado', 'viuvo'], true);
        $spouseRequired = in_array($status, ['casado', 'uniao_estavel'], true) && $this->hasAny(['estado_civil', 'conjuge_nome', 'conjuge_cpf']);
        $rentalType = $this->input('tipo_locacao', $lead->tipo_locacao?->value);
        $profile = $lead->tipo_solicitante;
        $requester = in_array($profile, ['locador', 'imobiliaria_nao_cadastrada'], true);
        $documentChanged = $this->exists('cpf') && $document !== ($lead->lead_empresa?->cnpj ?? $lead->cpf);
        $companyRequired = $company && $this->hasAny(['cpf', 'cpf_responsavel', 'nome_responsavel']);

        $rules = [
            'nome' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'email' => ['exclude'],
            'tel' => ['nullable', 'string', 'max:30'],
            'cpf' => ['bail', 'nullable', 'string', 'max:18', ...($documentChanged ? [new CpfOrCnpj] : [])],
            'tipo_solicitante' => ['exclude'],
            'tipo_locacao' => ['nullable', Rule::enum(TipoLocacao::class)],
            'descrever_atividade' => [Rule::excludeIf($rentalType !== 'comercial'), Rule::requiredIf($rentalType === 'comercial' && $this->hasAny(['tipo_locacao', 'descrever_atividade'])), 'nullable', 'string', 'max:55'],
            'cpf_responsavel' => [Rule::excludeIf(! $company), Rule::requiredIf($companyRequired), 'bail', 'nullable', 'string', 'size:11', new CpfOrCnpj],
            'nome_responsavel' => [Rule::excludeIf(! $company), Rule::requiredIf($companyRequired), 'nullable', 'string', 'min:3', 'max:55'],
            'estado_civil' => ['nullable', Rule::in(['solteiro', 'separado', 'casado', 'uniao_estavel', 'divorciado', 'viuvo'])],
            'conjuge_nome' => [Rule::excludeIf(! $spouse), Rule::requiredIf($spouseRequired), 'nullable', 'string', 'min:3', 'max:255'],
            'conjuge_cpf' => [Rule::excludeIf(! $spouse), Rule::requiredIf($spouseRequired), 'bail', 'nullable', 'string', 'size:11', new CpfOrCnpj],
            'responsavel_nome' => [Rule::excludeIf(! $requester), 'nullable', 'string', 'max:255'],
            'responsavel_email' => [Rule::excludeIf(! $requester), 'nullable', 'email', 'max:255'],
            'responsavel_telefone' => [Rule::excludeIf(! $requester), 'nullable', 'string', 'max:20'],
            'responsavel_preenchimento' => [Rule::excludeIf($profile !== 'imobiliaria_cadastrada'), 'nullable', 'string', 'min:3', 'max:255'],
            'cep' => ['nullable', 'string', 'max:8'],
            'estado' => ['nullable', 'string', 'size:2'],
            'cidade_imovel' => ['nullable', 'string', 'max:100'],
            'bairro' => ['nullable', 'string', 'max:100'],
            'logradouro' => ['nullable', 'string', 'max:255'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:100'],
        ];
        foreach (['valor_aluguel', 'valor_agua', 'valor_luz', 'valor_gas', 'valor_condominio', 'valor_iptu', 'outras_despesas'] as $field) {
            $rules[$field] = ['nullable', 'numeric', 'min:0'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'required' => 'Preencha o campo :attribute.',
            'string' => 'O campo :attribute deve ser um texto válido.',
            'max' => 'O campo :attribute excede o limite de :max.',
            'min' => 'O campo :attribute não atende ao mínimo de :min.',
            'size' => 'O campo :attribute deve ter :size caracteres.',
            'email' => 'Informe um e-mail válido em :attribute.',
            'numeric' => 'Informe um valor numérico em :attribute.',
            'in' => 'Selecione uma opção válida em :attribute.',
            'enum' => 'Selecione um tipo de locação válido.',
        ];
    }

    public function attributes(): array
    {
        return ['cpf' => 'CPF ou CNPJ', 'cpf_responsavel' => 'CPF do representante', 'nome_responsavel' => 'nome do representante', 'descrever_atividade' => 'descrever atividade', 'conjuge_nome' => 'nome do cônjuge', 'conjuge_cpf' => 'CPF do cônjuge'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $lead = $this->route('lead');
            if (filled($this->input('conjuge_cpf')) && $this->input('conjuge_cpf') === $this->input('cpf', $lead->cpf) && in_array($this->input('estado_civil', $lead->estado_civil), ['casado', 'uniao_estavel', 'divorciado', 'viuvo'], true)) {
                $validator->errors()->add('conjuge_cpf', 'O CPF do cônjuge deve ser diferente do CPF do pretendente.');
            }
            if ($this->exists('tipo_solicitante') && $this->input('tipo_solicitante') !== $lead->tipo_solicitante) {
                $validator->errors()->add('tipo_solicitante', 'O tipo de solicitante não pode ser alterado.');
            }
        }];
    }
}
