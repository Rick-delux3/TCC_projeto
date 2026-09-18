<?php

namespace App\Http\Requests;

use App\Models\Corretor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LinkLeadCompanyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $corretor = $this->user('admin');

        return $corretor instanceof Corretor
            && Gate::forUser($corretor)->allows('link-lead-company');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['bail', 'required', 'integer', Rule::exists('imobiliarias', 'id')->where('lead_form_active', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'company_id.required' => 'Selecione uma imobiliária para vincular ao lead.',
            'company_id.integer' => 'A imobiliária selecionada é inválida.',
            'company_id.exists' => 'Selecione uma imobiliária cadastrada e ativa.',
        ];
    }
}
