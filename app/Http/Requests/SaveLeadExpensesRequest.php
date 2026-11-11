<?php

namespace App\Http\Requests;

use App\Models\Lead;
use App\Services\Insurance\LeadReanalysisService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Salva as despesas do lead sem iniciar cotações. A reanálise é sempre por
 * companhia (ReanalyzeProviderExpensesRequest), que reaproveita estas regras.
 */
class SaveLeadExpensesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::guard($this->routeIs('admin.*') ? 'admin' : 'web')->user();
        $lead = $this->route('lead');

        return $user !== null && $lead instanceof Lead
            && Gate::forUser($user)->allows('reanalyzeWithChanges', $lead);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['revision' => ['required', 'string', 'size:64']] + self::expenseRules();
    }

    /**
     * Regras dos valores mensais, compartilhadas com a reanálise por companhia.
     *
     * @return array<string, array<int, string>>
     */
    public static function expenseRules(): array
    {
        $rules = [];
        foreach (LeadReanalysisService::EXPENSE_FIELDS as $field) {
            $rules[$field] = ['required', 'numeric', 'decimal:0,2', 'min:'.($field === 'valor_aluguel' ? '0.01' : '0'), 'max:9999999.99'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::expenseAttributes();
    }

    /**
     * @return array<string, string>
     */
    public static function expenseAttributes(): array
    {
        return ['valor_aluguel' => 'aluguel', 'valor_condominio' => 'condomínio', 'valor_iptu' => 'IPTU',
            'valor_agua' => 'água', 'valor_luz' => 'luz', 'valor_gas' => 'gás', 'outras_despesas' => 'outras despesas'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::expenseMessages();
    }

    /**
     * @return array<string, string>
     */
    public static function expenseMessages(): array
    {
        return [
            'revision.required' => 'Atualize a página antes de salvar as despesas.',
            'revision.size' => 'Atualize a página antes de salvar as despesas.',
            '*.required' => 'Informe o valor de :attribute. Use zero quando não houver despesa.',
            '*.numeric' => 'Informe um valor numérico válido para :attribute.',
            '*.decimal' => 'Use no máximo duas casas decimais para :attribute.',
            '*.min' => 'O valor de :attribute deve ser no mínimo :min.',
            '*.max' => 'O valor de :attribute deve ser no máximo :max.',
        ];
    }
}
