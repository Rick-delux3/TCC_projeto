<?php

namespace App\Services\Insurance\Payloads;

use App\Models\Lead;
use App\Services\CompanyApplicantService;

class TokioCompanyApplicantsPayloadBuilder
{
    public function __construct(private CompanyApplicantService $applicants) {}

    /**
     * @param  array<string, mixed>  $sharedApplicantData  Dados comuns do pretendente, já no contrato Tokio.
     * @return list<array<string, mixed>>
     */
    public function build(Lead $lead, array $sharedApplicantData = []): array
    {
        $applicants = $this->applicants->descriptions($lead);
        $sharedApplicantData['cnpj'] = $lead->rentalApplicantDocument();

        return array_map(fn (array $applicant): array => array_replace($sharedApplicantData, [
            'nome' => $applicant['nome'],
            'cpf' => $applicant['cpf'],
            'pretendentePrincipal' => $applicant['principal'] ? 'S' : 'N',
            'estadoCivil' => $lead->estado_civil === 'solteiro' ? '18485' : '18490',
            'vinculoEmpresa' => 'S',
            'tipoVinculo' => $applicant['principal'] ? 21147 : 21149,
        ]), $applicants);
    }
}
