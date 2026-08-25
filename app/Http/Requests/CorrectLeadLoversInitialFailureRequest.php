<?php

namespace App\Http\Requests;

use App\Models\Lead;
use App\Support\LeadLoversInitialFailureCatalog;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CorrectLeadLoversInitialFailureRequest extends FormRequest
{
    protected $stopOnFirstFailure = true;

    protected $errorBag = 'leadloversCorrection';

    public function authorize(): bool
    {
        $lead = $this->route('lead');

        if (! $lead instanceof Lead) {
            return false;
        }

        if ($this->routeIs('admin.leads.leadlovers.correct')) {
            $corretor = Auth::guard('admin')->user();

            return $corretor !== null
                && Gate::forUser($corretor)->allows('edit-leads');
        }

        $companyId = session('company_id');

        return Auth::guard('web')->check()
            && filled($companyId)
            && (int) $lead->company_id === (int) $companyId;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('tel')) {
            $normalized['tel'] = preg_replace(
                '/\D/',
                '',
                (string) $this->input('tel')
            );
        }

        if ($this->has('email')) {
            $normalized['email'] = mb_strtolower(
                trim((string) $this->input('email'))
            );
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        $lead = $this->route('lead');

        if (! $lead instanceof Lead) {
            throw $this->correctionValidationException(
                'O lead informado não foi encontrado.'
            );
        }

        $failure = app(
            LeadLoversInitialFailureCatalog::class
        )->describe($lead);

        if (! $failure['correctable']) {
            throw $this->correctionValidationException(
                'Esta falha não pode ser corrigida alterando os dados do lead.'
            );
        }

        return match ($failure['fields']) {
            ['tel'] => [
                'tel' => [
                    'bail',
                    'required',
                    'string',
                    'regex:/\A\d{10,11}\z/',
                    function (
                        string $attribute,
                        mixed $value,
                        Closure $fail
                    ) use ($lead): void {
                        $currentPhone = preg_replace(
                            '/\D/',
                            '',
                            (string) $lead->tel
                        );

                        if ((string) $value === $currentPhone) {
                            $fail(
                                'Informe um telefone diferente daquele que foi recusado.'
                            );
                        }
                    },
                ],
            ],

            ['email'] => [
                'email' => [
                    'bail',
                    'required',
                    'string',
                    'email:rfc',
                    'max:255',

                    function (
                        string $attribute,
                        mixed $value,
                        Closure $fail
                    ) use ($lead): void {
                        if (
                            mb_strtolower(trim((string) $value))
                            === mb_strtolower(trim((string) $lead->email))
                        ) {
                            $fail(
                                'Informe um e-mail diferente daquele que foi recusado.'
                            );
                        }
                    },

                    Rule::unique('leads', 'email')
                        ->ignore($lead->id)
                        ->where(function ($query) use ($lead) {
                            if ($lead->company_id !== null) {
                                return $query->where(
                                    'company_id',
                                    $lead->company_id
                                );
                            }

                            return $query
                                ->whereNull('company_id')
                                ->where('origem', $lead->origem);
                        }),
                ],
            ],

            default => throw $this->correctionValidationException(
                'O código de falha ainda não possui campos de correção configurados.'
            ),
        };
    }

    private function correctionValidationException(
        string $message
    ): ValidationException {
        return ValidationException::withMessages([
            'leadlovers' => $message,
        ])->errorBag($this->errorBag);
    }

    public function messages(): array
    {
        return [
            'tel.required' => 'Informe o telefone corrigido.',
            'tel.regex' => 'O telefone deve conter 10 ou 11 dígitos.',
            'email.required' => 'Informe o e-mail corrigido.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Este e-mail já pertence a outro lead do sistema.',
        ];
    }
}
