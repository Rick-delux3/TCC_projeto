<?php

namespace App\Http\Requests\Admin;

use App\Models\Imobiliaria;
use Illuminate\Validation\Rule;

trait ValidatesCompanyPartnerCodes
{
    protected function normalizePartnerCodes(): void
    {
        foreach (['tokio_partner_code', 'porto_partner_code'] as $field) {
            if (! $this->exists($field)) {
                continue;
            }
            $value = $this->input($field);
            if (is_string($value) || is_int($value)) {
                $value = trim((string) $value);
                if ($field === 'porto_partner_code' && preg_match('/^[0-9]{1,6}$/D', $value) === 1) {
                    $value = str_pad($value, 6, '0', STR_PAD_LEFT);
                }
                $value = $value === '' ? null : $value;
            }
            $this->merge([$field => $value]);
        }
    }

    protected function partnerCodeRules(): array
    {
        $company = $this->route('company');
        $rules = [];
        foreach (['tokio_partner_code' => 50, 'porto_partner_code' => 6] as $field => $length) {
            $unique = Rule::unique('imobiliarias', $field);
            if ($company instanceof Imobiliaria) {
                $unique->ignore($company);
            }
            $rules[$field] = ['bail', 'sometimes', 'nullable', 'string', 'max:'.$length, 'regex:/^[0-9]+$/D', $unique];
        }

        return $rules;
    }

    protected function partnerCodeMessages(): array
    {
        $messages = [];
        foreach (['tokio_partner_code' => 'Tokio', 'porto_partner_code' => 'Porto'] as $field => $name) {
            $messages[$field.'.string'] = "Informe um código válido da {$name}.";
            $messages[$field.'.regex'] = "O código da {$name} deve conter apenas números, sem espaços ou máscaras.";
            $messages[$field.'.max'] = "O código da {$name} deve ter no máximo :max dígitos.";
            $messages[$field.'.unique'] = "Este código da {$name} já está associado a outra imobiliária.";
        }

        return $messages;
    }
}
