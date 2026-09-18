<?php

namespace App\Actions\Companies;

use App\Models\Imobiliaria;

class SyncCompanyDepartments
{
    /**
     * Executado na transação do cadastro ou da edição, com a imobiliária bloqueada na edição.
     *
     * @param  list<array{key: string, name: string, email?: string|null}>  $departments
     */
    public function execute(Imobiliaria $company, array $departments): bool
    {
        $existing = $company->setores()->get()->keyBy('key');
        $changed = false;

        foreach ($departments as $department) {
            $sector = $existing->get($department['key'])
                ?? $company->setores()->make(['key' => $department['key']]);

            $sector->fill([
                'name' => $department['name'],
                'email' => $department['email'] ?? null,
            ]);

            if (! $sector->exists || $sector->isDirty()) {
                $sector->save();
                $changed = true;
            }
        }

        $deleted = $company->setores()
            ->whereNotIn('key', array_column($departments, 'key'))
            ->delete();

        $company->unsetRelation('setores');

        return $changed || $deleted > 0;
    }
}
