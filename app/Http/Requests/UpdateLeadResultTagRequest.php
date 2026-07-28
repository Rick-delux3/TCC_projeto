<?php

namespace App\Http\Requests;

use App\Support\ManualLeadResultTags;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Override;

class UpdateLeadResultTagRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $corretor = $this->user('admin');

        return $corretor !== null && Gate::forUser($corretor)->allows('manage-lead-tags');
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        if(! $this->has('result')) {
            return;
        }

        $this->merge([
            'result' => mb_strtolower(
                trim((string) $this->input('result'))
            ),
        ]);
    }



    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'result' => [
                'bail',
                'required',
                'string',
                Rule::in(ManualLeadResultTags::keys()),
            ],
        ];
    }

    #[Override]
    public function messages(): array
    {
         return [
            'result.required' =>
                'Selecione um resultado para o lead.',

            'result.string' =>
                'O resultado selecionado é inválido.',

            'result.in' =>
                'O resultado selecionado não é permitido.',
        ];
    }

    #[Override]
    public function attributes(): array
    {
        return [
            'result' => 'resultado'
        ];
    }
}
