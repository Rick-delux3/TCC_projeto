<?php

namespace App\Http\Requests;

use App\Models\Lead;
use App\Services\Insurance\LeadReanalysisService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ReanalyzeLeadExpensesRequest extends FormRequest
{
    protected $errorBag = 'expenseReanalysis';

    public function authorize(): bool
    {
        $user = Auth::guard($this->routeIs('admin.*') ? 'admin' : 'web')->user();
        $lead = $this->route('lead');

        return $user !== null && $lead instanceof Lead
            && Gate::forUser($user)->allows('reanalyzeWithChanges', $lead);
    }

    public function rules(): array
    {
        $rules = ['revision' => ['required', 'string', 'size:64']];
        foreach (LeadReanalysisService::EXPENSE_FIELDS as $field) {
            $rules[$field] = ['required', 'numeric', 'decimal:0,2', 'min:'.($field === 'valor_aluguel' ? '0.01' : '0'), 'max:9999999.99'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return ['valor_aluguel' => 'aluguel', 'valor_condominio' => 'condomínio', 'valor_iptu' => 'IPTU',
            'valor_agua' => 'água', 'valor_luz' => 'luz', 'valor_gas' => 'gás', 'outras_despesas' => 'outras despesas'];
    }

    public function messages(): array
    {
        return [
            'revision.required' => 'Atualize a página antes de solicitar uma nova cotação.',
            'revision.size' => 'Atualize a página antes de solicitar uma nova cotação.',
            '*.required' => 'Informe o valor de :attribute. Use zero quando não houver despesa.',
            '*.numeric' => 'Informe um valor numérico válido para :attribute.',
            '*.decimal' => 'Use no máximo duas casas decimais para :attribute.',
            '*.min' => 'O valor de :attribute deve ser no mínimo :min.',
            '*.max' => 'O valor de :attribute deve ser no máximo :max.',
        ];
    }
}
