<?php

namespace App\Services\Insurance\Payloads;

use App\Models\InsuranceAnalysis;
use App\Models\Lead;
use Illuminate\Support\Carbon;

class RentalGuaranteeQuotePayloadBuilder
{
    public function build(InsuranceAnalysis $analysis): array
    {
        $analysis->loadMissing([
            'lead.company',
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'lead.locador',
            'lead.imobiliariaInformada',
        ]);

        $lead = $analysis->lead;

        if (!$lead) {
            throw new \RuntimeException('Lead nao encontrado para montar o payload da Pottencial.');
        }

        $leaseMonths = (int) ($analysis->multiple ?: config('services.pottencial.default_lease_months', 30));
        $startDate = $this->dateValue($analysis->lease_start_date ?? now());
        $endDate = $analysis->lease_end_date
            ? $this->dateValue($analysis->lease_end_date)
            : $startDate->copy()->addMonthsNoOverflow($leaseMonths);

        $policyHolderDocument = \only_numbers($lead->cpf ?? '');

        if (!$policyHolderDocument) {
            throw new \RuntimeException('CPF do solicitante nao encontrado para envio da cotacao.');
        }

        return [
            'policyPeriodStart' => $startDate->format('Y-m-d'),
            'policyPeriodEnd' => $endDate->format('Y-m-d'),
            'policyType' => $this->policyType(),

            'commissionedAgents' => $this->commissionedAgents($analysis),
            'participants' => $this->participants($lead, $policyHolderDocument),

            'paymentConditions' => [
                'paymentType' => $analysis->payment_type
                    ?? config('services.pottencial.default_payment_type', 'Boleto'),
                'installments' => $analysis->installments
                    ?? (int) config('services.pottencial.default_installments', 12),
            ],

            'riskObjects' => [
                [
                    'type' => 'FiancaLocaticia',
                    'tenantDocumentNumber' => $policyHolderDocument,
                    'startLeaseContract' => $startDate->format('Y-m-d'),
                    'endLeaseContract' => $endDate->format('Y-m-d'),
                    'coverages' => $this->coverages($lead, $leaseMonths),
                    'riskLocation' => [
                        'address' => $this->addressFromLead($lead),
                    ],
                    'expenses' => $this->expenses($lead),
                    'planKey' => $analysis->plan_key
                        ?? config('services.pottencial.default_plan_key', 'traditional'),
                    'occupation' => 'Residencial',
                    'inhabited' => (bool) $analysis->inhabited,
                    'multiple' => $leaseMonths,
                    'assistanceServices' => [
                        [
                            'key' => config('services.pottencial.default_assistance', 'Complete'),
                        ],
                    ],
                ],
            ],
        ];
    }

    private function commissionedAgents(InsuranceAnalysis $analysis): array
    {
        $lead = $analysis->lead;
        $brokerDocument = \only_numbers(config('services.pottencial.broker_document'));

        if (!$brokerDocument) {
            throw new \RuntimeException('Documento do broker nao configurado para envio da cotacao.');
        }

        $agents = [
            [
                'documentNumber' => $brokerDocument,
                'role' => 'Broker',
                'commissionPercentage' => (float) config('services.pottencial.default_commission', 0.10),
                'lead' => true,
            ],
        ];

        $companyDocument = \only_numbers($lead?->company?->cnpj ?? '');

        if ($lead?->tipo_solicitante === 'imobiliaria_cadastrada' && $companyDocument) {
            $agents[] = [
                'documentNumber' => $companyDocument,
                'role' => 'PolicyOwner',
                'lead' => false,
                'isPayer' => true,
            ];
        }

        return $agents;
    }

    private function policyType(): string
    {
        $policyType = (string) config('services.pottencial.default_policy_type', 'Unico');

        return in_array(mb_strtolower($policyType), ['unique', 'unico'], true)
            ? 'Unico'
            : $policyType;
    }

    private function participants(Lead $lead, string $policyHolderDocument): array
    {
        $participants = [
            $this->participant(
                role: 'PolicyHolder',
                document: $policyHolderDocument,
                contact: $this->leadContact($lead),
                main: true
            ),
        ];

        $beneficiaryDocument = \only_numbers(config('services.pottencial.default_beneficiary_document'));

        if ($beneficiaryDocument) {
            $participants[] = $this->participant(
                role: 'Beneficiary',
                document: $beneficiaryDocument,
                contact: $this->beneficiaryContact($lead),
                extra: [
                    'participationPercentage' => 1,
                ]
            );
        }

        return $participants;
    }

    private function participant(
        string $role,
        string $document,
        array $contact,
        bool $main = false,
        array $extra = []
    ): array {
        $participant = [
            'documentNumber' => $document,
            'role' => $role,
            'address' => $this->addressFromLead($contact['lead']),
            'contact' => [
                'name' => $contact['name'],
                'email' => $contact['email'],
                'phoneNumber' => '',
                'cellPhoneNumber' => $contact['phone'],
            ],
        ];

        if ($main) {
            $participant['main'] = true;
        }

        return array_merge($participant, $extra);
    }

    private function leadContact(Lead $lead): array
    {
        return [
            'lead' => $lead,
            'name' => $lead->nome,
            'email' => $lead->email,
            'phone' => \only_numbers($lead->tel ?? ''),
        ];
    }

    private function beneficiaryContact(Lead $lead): array
    {
        $locador = $lead->locador;

        return [
            'lead' => $lead,
            'name' => $locador?->nome ?: $lead->nome,
            'email' => $locador?->email ?: $lead->email,
            'phone' => \only_numbers($locador?->telefone ?: $lead->tel ?: ''),
        ];
    }

    private function addressFromLead(Lead $lead): array
    {
        $endereco = $lead->endereco;

        return [
            'street' => $endereco?->logradouro ?? '',
            'number' => $endereco?->numero ?? '',
            'district' => $endereco?->bairro ?? '',
            'city' => $endereco?->cidade_imovel ?? '',
            'state' => $endereco?->estado ?? '',
            'zipCode' => $endereco?->cep ?? '',
            'complement' => $endereco?->complemento ?? '',
            'country' => 'BRA',
            'type' => 'Residential',
        ];
    }

    private function coverages(Lead $lead, int $months): array
    {
        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;
        $condominio = $this->expenseValue($lead, 'valor_condominio') ?? 0.0;
        $iptu = $this->expenseValue($lead, 'valor_iptu') ?? 0.0;
        $gas = $this->expenseValue($lead, 'valor_gas') ?? 0.0;
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

    private function expenses(Lead $lead): array
    {
        return array_values(array_filter([
            [
                'description' => 'VALOR_ALUGUEL',
                'value' => $this->expenseValue($lead, 'valor_aluguel') ?? 0.0,
            ],
            [
                'description' => 'VALOR_CONDOMINIO',
                'value' => $this->expenseValue($lead, 'valor_condominio') ?? 0.0,
            ],
            [
                'description' => 'VALOR_IPTU',
                'value' => $this->expenseValue($lead, 'valor_iptu') ?? 0.0,
            ],
            [
                'description' => 'VALOR_GAS',
                'value' => $this->expenseValue($lead, 'valor_gas') ?? 0.0,
            ],
            [
                'description' => 'VALOR_AGUA',
                'value' => $this->valorAgua($lead),
            ],
            [
                'description' => 'VALOR_LUZ',
                'value' => $this->valorLuz($lead),
            ],
            [
                'description' => 'OUTRAS_DESPESAS',
                'value' => $this->expenseValue($lead, 'outras_despesas') ?? 0.0,
            ],
        ], fn (array $expense) => $expense['value'] > 0));
    }

    private function valorAgua(Lead $lead): float
    {
        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;
        $valorAgua = $this->expenseValue($lead, 'valor_agua');

        return $valorAgua ?? ($aluguel * 0.10);
    }

    private function valorLuz(Lead $lead): float
    {
        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;
        $valorLuz = $this->expenseValue($lead, 'valor_luz');

        return $valorLuz ?? ($aluguel * 0.10);
    }

    private function expenseValue(Lead $lead, string $field): ?float
    {
        $value = $lead->despesas?->{$field} ?? null;

        return $value !== null && $value !== '' ? (float) $value : null;
    }

    private function dateValue(mixed $value): Carbon
    {
        return $value instanceof Carbon
            ? $value
            : Carbon::parse($value);
    }
}
