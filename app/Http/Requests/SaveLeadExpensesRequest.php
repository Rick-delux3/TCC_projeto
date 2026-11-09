<?php

namespace App\Http\Requests;

use App\Models\Lead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

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
        return (new ReanalyzeLeadExpensesRequest)->rules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (new ReanalyzeLeadExpensesRequest)->attributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return (new ReanalyzeLeadExpensesRequest)->messages();
    }
}
