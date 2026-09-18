<?php

namespace App\Http\Requests\Admin;

trait ValidatesCompanyDepartments
{
    protected function normalizeDepartments(): void
    {
        $departments = $this->input('setores');

        if (! is_array($departments)) {
            return;
        }

        foreach ($departments as &$department) {
            if (! is_array($department)) {
                continue;
            }

            foreach (['key', 'name', 'email'] as $field) {
                if (! isset($department[$field]) || ! is_string($department[$field])) {
                    continue;
                }

                $value = trim($department[$field]);
                $department[$field] = $field === 'name' ? $value : mb_strtolower($value);
            }

            if (($department['email'] ?? null) === '') {
                $department['email'] = null;
            }
        }

        $this->merge(['setores' => $departments]);
    }

    /** @return array<string, list<string>> */
    protected function departmentRules(): array
    {
        return [
            'setores' => ['sometimes', 'array', 'list', 'max:50'],
            'setores.*' => ['required', 'array:key,name,email'],
            'setores.*.key' => ['bail', 'required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_-]*$/D', 'distinct:ignore_case'],
            'setores.*.name' => ['bail', 'required', 'string', 'max:150'],
            'setores.*.email' => ['bail', 'nullable', 'string', 'email:rfc', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    protected function departmentMessages(): array
    {
        return [
            'setores.array' => 'Informe uma lista válida de setores.',
            'setores.list' => 'Informe uma lista válida de setores.',
            'setores.max' => 'Cadastre no máximo 50 setores por imobiliária.',
            'setores.*.required' => 'Informe os dados do setor.',
            'setores.*.array' => 'Cada setor deve conter apenas identificador, nome e e-mail.',
            'setores.*.key.required' => 'Informe o identificador do setor.',
            'setores.*.key.string' => 'O identificador do setor deve ser um texto.',
            'setores.*.key.max' => 'O identificador do setor deve ter no máximo 100 caracteres.',
            'setores.*.key.regex' => 'Use um identificador iniciado por letra, com letras sem acento, números, hífen ou sublinhado.',
            'setores.*.key.distinct' => 'Não repita o identificador de um setor na mesma imobiliária.',
            'setores.*.name.required' => 'Informe o nome do setor.',
            'setores.*.name.string' => 'O nome do setor deve ser um texto.',
            'setores.*.name.max' => 'O nome do setor deve ter no máximo 150 caracteres.',
            'setores.*.email.string' => 'Informe um e-mail válido para o setor.',
            'setores.*.email.email' => 'Informe um e-mail válido para o setor.',
            'setores.*.email.max' => 'O e-mail do setor deve ter no máximo 255 caracteres.',
        ];
    }
}
