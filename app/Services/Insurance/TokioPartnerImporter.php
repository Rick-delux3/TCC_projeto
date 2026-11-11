<?php

namespace App\Services\Insurance;

class TokioPartnerImporter
{
    public function import(array $rows, string $source, bool $apply = false): array
    {
        return (new \App\Models\Imobiliaria)->getConnection()->transaction(function () use ($rows, $source, $apply): array {
            $companies = \App\Models\Imobiliaria::query()->lockForUpdate()->get();
            $active = collect($rows)->filter(fn ($row) => mb_strtolower(trim($row['status'])) === 'ativo');
            $codes = $active->countBy('code');
            $documents = $active->countBy('document');
            $report = [];
            foreach ($rows as $row) {
                $action = 'ignored';
                $reason = 'Parceiro não ativo.';
                $company = null;
                if (mb_strtolower(trim($row['status'])) === 'ativo') {
                    $validator = \Illuminate\Support\Facades\Validator::make($row, [
                        'code' => ['required', 'regex:/^\d{1,50}$/D'], 'name' => ['required', 'string', 'max:255'],
                        'document' => ['required', new \App\Rules\CpfOrCnpj],
                    ]);
                    if ($validator->fails() || ($codes[$row['code']] ?? 0) > 1 || ($documents[$row['document']] ?? 0) > 1) {
                        $action = 'conflict';
                        $reason = 'Dados inválidos ou código/documento duplicado na planilha.';
                    } else {
                        $byDocument = $companies->filter(fn ($item) => \only_numbers($item->cnpj) === $row['document']);
                        $byName = $companies->filter(fn ($item) => $this->name($item->name) === $this->name($row['name']));
                        $byCode = $companies->filter(fn ($item) => (string) $item->tokio_partner_code === $row['code']);
                        $matches = $byDocument->isNotEmpty() ? $byDocument : $byName;
                        $company = $matches->first();
                        $conflict = $matches->count() > 1 || $byCode->contains(fn ($item) => $item->id !== $company?->id)
                            || ($company && filled($company->cnpj) && \only_numbers($company->cnpj) !== $row['document'])
                            || ($company && filled($company->tokio_partner_code) && (string) $company->tokio_partner_code !== $row['code']);
                        if ($conflict) {
                            $action = 'conflict';
                            $reason = 'Identidade, nome ou código conflita com cadastro existente.';
                        } else {
                            $action = $company ? ((string) $company->tokio_partner_code === $row['code'] ? 'unchanged' : 'linked') : 'created';
                            $reason = $company ? 'Correspondência por documento ou nome normalizado.' : 'Cadastro incompleto, com formulário desativado.';
                            if ($apply && $action !== 'unchanged') {
                                $company ??= new \App\Models\Imobiliaria;
                                if (! $company->exists) {
                                    $company->forceFill(['name' => $row['name'], 'email' => null, 'phone' => null, 'city' => null, 'state' => null,
                                        'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(64)), 'lead_form_active' => false]);
                                }
                                $company->forceFill(['cnpj' => $row['document'], 'tokio_partner_code' => $row['code'], 'tokio_partner_source' => $source])->save();
                                if (! $companies->contains('id', $company->id)) {
                                    $companies->push($company);
                                }
                            }
                        }
                    }
                }
                $report[] = ['row' => $row['row'], 'code' => $row['code'], 'company_id' => $company?->id, 'action' => $action, 'reason' => $reason];
            }

            return $report;
        });
    }

    private function name(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($name))));
    }
}
