<?php

namespace App\Services\Insurance\Payloads;

use App\Models\InsuranceAnalysis;

class RentalGuaranteeQuotePayloadBuilder
{
    public function build(InsuranceAnalysis $analysis): array
    {
        $lead = $analysis->lead;

        $startDate = $analysis->lease_start_date ?? now();
        $endDate = $analysis->lease_end_date ?? now()->addMonthsNoOverflow(30);
        $policyHolderDocument = \only_numbers(
            $lead->cpf_cnpj
            ?? $lead->cpf
            ?? ''
        );

        return [
            'policyPeriodStart' => $startDate->format('Y-m-d'),
            'policyPeriodEnd' => $endDate->format('Y-m-d'),
            'policyType' => config('services.pottencial.default_policy_type', 'Unique'),

            'commissionedAgents' => $this->commissionedAgents($analysis),

            'participants' => [
                $this->policyHolder($lead, $policyHolderDocument),
            ],

            'riskObjects' => [
                [
                    'type' => 'FiancaLocaticia',
                    'planKey' => $analysis->plan_key ?? 'traditional',
                    'multiple' => $analysis->multiple ?? 30,
                    'occupation' => 'Residencial',
                    'inhabited' => (bool) $analysis->inhabited,

                    'tenantDocumentNumber' => $policyHolderDocument,

                    'startLeaseContract' => $startDate->format('Y-m-d'),
                    'endLeaseContract' => $endDate->format('Y-m-d'),

                    'riskLocation' => [
                        'address' => $this->addressFromLead($lead),
                    ],

                    'coverages' => $this->coverages($lead, $analysis->multiple ?? 30),
                    'expenses' => $this->expenses($lead),
                ],
            ],

            'paymentConditions' => [
                'paymentType' => $analysis->payment_type
                    ?? config('services.pottencial.default_payment_type', 'Boleto'),

                'installments' => $analysis->installments
                    ?? (int) config('services.pottencial.default_installments', 12),
            ],

            'assistanceServices' => [
                [
                    'key' => config('services.pottencial.default_assistance', 'Complete'),
                ],
            ],
        ];
    }

    private function commissionedAgents(InsuranceAnalysis $analysis): array
    {
        $lead = $analysis->lead;

        $agents = [
            [
                'documentNumber' => \only_numbers(config('services.pottencial.broker_document')),
                'role' => 'Broker',
                'commissionPercentage' => (float) config('services.pottencial.default_commission', 0.20),
                'lead' => true,
            ],
        ];

        
        if($lead->tipo_solicitante === 'imobiliaria_cadastrada'){
            $companyCNPJ = \only_numbers($lead->company->cnpj);
            
            if (empty($companyCNPJ)) {
                throw new \RuntimeException('CNPJ da imobiliária não encontrado para envio da cotação.');
            }

                $agents[] = [
                    'documentNumber' => $companyCNPJ,
                    'role' => 'PolicyOwner',
                    'lead' => false,
                    'isPayer' => true,
                ];
        }

        return $agents;
    }

    private function policyHolder($lead, string $document): array
    {
        return [
            'documentNumber' => $document,
            'role' => 'PolicyHolder',
            'main' => true,
            'address' => $this->addressFromLead($lead),
            'contact' => [
                'name' => $lead->nome,
                'email' => $lead->email,
                'phoneNumber' => '',
                'cellPhoneNumber' => \only_numbers($lead->tel ?? ''),
            ],
        ];
    }

    private function addressFromLead($lead): array
    {
        return [
            'street' => $lead->logradouro,
            'number' => $lead->numero,
            'district' => $lead->bairro,
            'city' => $lead->cidade_imovel,
            'state' => $lead->estado,
            'zipCode' => $lead->cep,
            'complement' => $lead->complemento ?? '',
            'country' => 'BRA',
            'type' => 'Residential',
        ];
    }

    private function coverages($lead, int $months): array
    {
        $aluguel = (float) ($lead->valor_aluguel ?? 0);
        $condominio = (float) ($lead->valor_condominio ?? 0);
        $iptu = (float) ($lead->valor_iptu ?? 0);
        $gas = (float) ($lead->valor_gas ?? 0);
        $agua = $this->valorAgua($lead);
        $luz = $this->valorLuz($lead);

        return array_values(array_filter([
            [
                'key' => 'basica',
                'insuredAmount' => max($aluguel * $months, 12000),
            ],
            [
                'key' => 'condominio',
                'insuredAmount' => $condominio * $months,
            ],
            [
                'key' => 'iptu',
                'insuredAmount' => $iptu * $months,
            ],
            [
                'key' => 'gas',
                'insuredAmount' => $gas * $months,
            ],
            [
                'key' => 'agua',
                'insuredAmount' => $agua * $months,
            ],
            [
                'key' => 'luz',
                'insuredAmount' => $luz * $months,
            ],
            [
                'key' => 'danos',
                'insuredAmount' => max($aluguel * 6, 6000),
            ],
            [
                'key' => 'pintura',
                'insuredAmount' => max($aluguel * 6, 6000),
            ],
            [
                'key' => 'multa-rescisao',
                'insuredAmount' => max($aluguel * 3, 3000),
            ],
        ], fn (array $coverage) => $coverage['insuredAmount'] > 0));
    }

    private function expenses($lead): array
    {
        return array_values(array_filter([
            [
                'description' => 'VALOR_ALUGUEL',
                'value' => (float) ($lead->valor_aluguel ?? 0),
            ],
            [
                'description' => 'VALOR_CONDOMINIO',
                'value' => (float) ($lead->valor_condominio ?? 0),
            ],
            [
                'description' => 'VALOR_IPTU',
                'value' => (float) ($lead->valor_iptu ?? 0),
            ],
            [
                'description' => 'VALOR_GAS',
                'value' => (float) ($lead->valor_gas ?? 0),
            ],
            [
                'description' => 'VALOR_AGUA',
                'value' => $this->valorAgua($lead),
            ],
            [
                'description' => 'VALOR_LUZ',
                'value' => $this->valorLuz($lead),
            ],
        ], fn (array $expense) => $expense['value'] > 0));
    }

    private function valorAgua($lead): float
    {
        $aluguel = (float) ($lead->valor_aluguel ?? 0);

        if ($lead->valor_agua !== null && $lead->valor_agua !== '') {
            return (float) $lead->valor_agua;
        }

        return $aluguel * 0.10;
    }

    private function valorLuz($lead): float
    {
        $aluguel = (float) ($lead->valor_aluguel ?? 0);

        if ($lead->valor_luz !== null && $lead->valor_luz !== '') {
            return (float) $lead->valor_luz;
        }

        return $aluguel * 0.10;
    }

   
}