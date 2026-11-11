<?php

namespace App\Http\Requests;

use App\Models\InsuranceAnalysis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ReanalyzeProviderExpensesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::guard($this->routeIs('admin.*') ? 'admin' : 'web')->user();
        $analysis = $this->route('analysis');
        $lead = $analysis instanceof InsuranceAnalysis ? $analysis->lead()->first() : null;

        return $user !== null && $lead !== null
            && Gate::forUser($user)->allows('reanalyzeWithChanges', $lead);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return SaveLeadExpensesRequest::expenseRules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return SaveLeadExpensesRequest::expenseAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return SaveLeadExpensesRequest::expenseMessages();
    }
}
