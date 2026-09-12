<?php

namespace App\Http\Requests;

use App\Services\CorretorDashboardLeadQuery;
use App\Support\LeadLoversInitialFailureCatalog;
use App\Support\ManualLeadResultTags;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterCorretorDashboardRequest extends FormRequest
{
    protected $errorBag = 'leadFilters';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'lead_name' => ['nullable', 'string', 'max:255'],
            'imobiliaria' => ['nullable', 'string', 'regex:/\A(?:sem_vinculo|[1-9][0-9]{0,18})\z/'],
            'tipo_solicitante' => ['nullable', 'string', Rule::in(array_keys(CorretorDashboardLeadQuery::requesterOptions()))],
            'resultado' => ['nullable', 'string', Rule::in([...ManualLeadResultTags::keys(), CorretorDashboardLeadQuery::WITHOUT_RESULT])],
            'leadlovers_sync' => ['nullable', 'string', Rule::in(array_keys(app(LeadLoversInitialFailureCatalog::class)->dashboardSyncOptions()))],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['lead_name', 'imobiliaria', 'tipo_solicitante', 'resultado', 'leadlovers_sync'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = $field === 'lead_name'
                    ? trim($value)
                    : mb_strtolower(trim($value));
            }
        }

        $result = $normalized['resultado'] ?? null;
        $aliases = ['aprovado' => ManualLeadResultTags::APPROVED, 'recusado' => ManualLeadResultTags::REJECTED, 'no_result' => CorretorDashboardLeadQuery::WITHOUT_RESULT];

        if (is_string($result)) {
            $normalized['resultado'] = $aliases[$result] ?? $result;
        }

        $this->merge($normalized);
    }

    public function messages(): array
    {
        return [
            'lead_name.string' => 'Informe um texto para buscar leads.',
            'lead_name.max' => 'A busca deve ter no máximo 255 caracteres.',
            'imobiliaria.string' => 'Informe um vínculo válido.',
            'imobiliaria.regex' => 'Selecione uma imobiliária ou a opção sem vínculo.',
            'tipo_solicitante.string' => 'Informe um perfil válido.',
            'tipo_solicitante.in' => 'Selecione um perfil válido.',
            'resultado.string' => 'Informe um resultado válido.',
            'resultado.in' => 'Selecione um resultado válido.',
            'leadlovers_sync.string' => 'Informe uma opção de envio válida.',
            'leadlovers_sync.in' => 'Selecione uma opção de envio válida.',
            'page.integer' => 'Informe uma página válida.',
            'page.min' => 'A página deve ser maior que zero.',
            'page.max' => 'A página informada excede o limite permitido.',
        ];
    }
}
