<?php

namespace App\Services\Leads;

use App\Enums\TipoLocacao;
use App\Enums\TipoVinculoEmpresa;
use App\Exceptions\InvalidInsuranceAnalysisPayload;
use App\Models\Lead;
use App\Rules\CpfOrCnpj;
use Illuminate\Support\Facades\Validator;

class CompanyApplicantService
{
    public function sync(Lead $lead): void
    {
        $lead->load(['lead_empresa', 'conjuge']);
        $eligible = $this->eligible($lead);
        $lead->forceFill(['tipo_vinculo' => $eligible ? TipoVinculoEmpresa::Titular : null]);
        if ($lead->isDirty('tipo_vinculo')) {
            $lead->save();
        }

        if ($lead->conjuge !== null) {
            $lead->conjuge->forceFill([
                'tipo_vinculo' => $eligible && $this->hasSpouse($lead) ? TipoVinculoEmpresa::Socio : null,
            ]);
            if ($lead->conjuge->isDirty('tipo_vinculo')) {
                $lead->conjuge->save();
            }
        }
    }

    /** @return list<array{nome: string, cpf: string, principal: bool, estado_civil: string, tipo_vinculo: string}> */
    public function descriptions(Lead $lead): array
    {
        $lead->loadMissing(['lead_empresa', 'conjuge']);
        if (! $this->eligible($lead) || $lead->tipo_vinculo !== TipoVinculoEmpresa::Titular) {
            throw new InvalidInsuranceAnalysisPayload('Vínculo empresarial não definido para este pretendente comercial.');
        }

        $applicants = [[
            'nome' => $lead->rentalApplicantNameForCpf(),
            'cpf' => $lead->rentalApplicantCpf(),
            'principal' => true,
            'estado_civil' => match ($lead->estado_civil) {
                'solteiro' => 'Solteiro(a)',
                'casado' => 'Casado(a)',
                'uniao_estavel' => 'União estável',
            },
            'tipo_vinculo' => $lead->tipo_vinculo->label(),
        ]];

        if ($this->hasSpouse($lead)) {
            if ($lead->conjuge?->tipo_vinculo !== TipoVinculoEmpresa::Socio) {
                throw new InvalidInsuranceAnalysisPayload('Cônjuge e vínculo de sócio não definidos para a cotação comercial.');
            }
            $applicants[] = [
                'nome' => (string) $lead->conjuge->nome,
                'cpf' => (string) CpfOrCnpj::normalize($lead->conjuge->cpf),
                'principal' => false,
                'estado_civil' => $applicants[0]['estado_civil'],
                'tipo_vinculo' => $lead->conjuge->tipo_vinculo->label(),
            ];
        }

        $validator = Validator::make(['pretendentes' => $applicants], [
            'pretendentes.*.cpf' => ['required', 'string', 'size:11', 'distinct', new CpfOrCnpj],
            'pretendentes.*.nome' => ['required', 'string', 'min:3'],
        ]);
        if ($validator->fails()) {
            throw new InvalidInsuranceAnalysisPayload('Informe nomes e CPFs válidos e distintos para os pretendentes da empresa.');
        }

        return $applicants;
    }

    private function eligible(Lead $lead): bool
    {
        return $lead->tipo_locacao === TipoLocacao::COMERCIAL
            && strlen($lead->rentalApplicantDocument()) === 14
            && in_array($lead->estado_civil, ['solteiro', 'casado', 'uniao_estavel'], true);
    }

    private function hasSpouse(Lead $lead): bool
    {
        return in_array($lead->estado_civil, ['casado', 'uniao_estavel'], true);
    }
}
