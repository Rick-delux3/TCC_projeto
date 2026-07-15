<?php

namespace App\Services\Insurance\Payloads;

use App\Models\InsuranceAnalysis;
use App\Models\Lead;
use Illuminate\Support\Carbon;

class RentalGuaranteeQuotePayloadBuilder
{
    public function build(InsuranceAnalysis $analysis): array
    {
        /*
         * Carrega os relacionamentos necessários para montar o payload.
         * O vínculo com a imobiliária cadastrada usa o relacionamento
         * imobiliariaVinculada() definido no model Lead.
         */
        $relations = [
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'lead.locador',
            'lead.imobiliariaInformada',
        ];

        $relations[] = 'lead.imobiliariaVinculada';

        $analysis->loadMissing($relations);

        $lead = $analysis->lead;

        if (!$lead) {
            throw new \RuntimeException('Lead não encontrado para montar o payload da Pottencial.');
        }

        $policyHolderDocument = \only_numbers($lead->cpf ?? '');

        if (!$policyHolderDocument) {
            throw new \RuntimeException('CPF do solicitante não encontrado para envio da cotação.');
        }

        $this->validateRequiredLeadData($lead);

        /*
         * Regra do seu TCC:
         * contrato padrão de 30 meses.
         */
        $leaseMonths = (int) ($analysis->multiple ?: config('services.pottencial.default_lease_months', 30));

        $startDate = $this->dateValue($analysis->lease_start_date ?? now());

        $endDate = $analysis->lease_end_date
            ? $this->dateValue($analysis->lease_end_date)
            : $startDate->copy()->addMonthsNoOverflow($leaseMonths);

        return [
            'policyPeriodStart' => $startDate->format('Y-m-d'),
            'policyPeriodEnd' => $endDate->format('Y-m-d'),
            'policyType' => $this->policyType(),

            /*
             * Broker sempre.
             * PolicyOwner somente para imobiliária cadastrada com CNPJ.
             */
            'commissionedAgents' => $this->commissionedAgents($analysis),

            /*
             * Regra atual:
             * somente PolicyHolder.
             * Não enviar Beneficiary agora.
             */
            'participants' => $this->participants($lead, $policyHolderDocument),

            /*
             * paymentConditions fica no nível raiz.
             */
            'paymentConditions' => [
                'paymentType' => $analysis->payment_type
                    ?? config('services.pottencial.default_payment_type', 'Boleto'),

                'installments' => $analysis->installments
                    ?? (int) config('services.pottencial.default_installments', 12),
            ],

            /*
             * assistanceServices também deve ficar no nível raiz,
             * não dentro de riskObjects.
             */
            'assistanceServices' => [
                [
                    'key' => config('services.pottencial.default_assistance', 'Complete'),
                ],
            ],

            'riskObjects' => [
                [
                    'type' => 'FiancaLocaticia',
                    'planKey' => $analysis->plan_key
                        ?? config('services.pottencial.default_plan_key', 'traditional'),

                    'multiple' => $leaseMonths,
                    'occupation' => 'Residencial',
                    'inhabited' => (bool) $analysis->inhabited,

                    'tenantDocumentNumber' => $policyHolderDocument,

                    'startLeaseContract' => $startDate->format('Y-m-d'),
                    'endLeaseContract' => $endDate->format('Y-m-d'),

                    'riskLocation' => [
                        'address' => $this->addressFromLead($lead),
                    ],

                    'coverages' => $this->coverages($lead, $leaseMonths),
                    'expenses' => $this->expenses($lead),
                ],
            ],
        ];
    }

    private function commissionedAgents(InsuranceAnalysis $analysis): array
    {
        $lead = $analysis->lead;

        $brokerDocument = \only_numbers(config('services.pottencial.broker_document'));

        if (!$brokerDocument) {
            throw new \RuntimeException('Documento do broker não configurado para envio da cotação.');
        }

        $agents = [
            [
                'documentNumber' => $brokerDocument,
                'role' => 'Broker',
                'commissionPercentage' => (float) config('services.pottencial.default_commission', 0.10),
                'lead' => true,
            ],
        ];

        /*
         * PolicyOwner somente quando for imobiliária cadastrada.
         */
        $imobiliaria = $lead?->imobiliariaVinculada;

        $companyDocument = \only_numbers($imobiliaria?->cnpj ?? '');

        if ($lead?->tipo_solicitante === 'imobiliaria_cadastrada' && $companyDocument) {
            $policyOwner = [
                'documentNumber' => $companyDocument,
                'role' => 'PolicyOwner',
            ];

            /*
             * Só envia commissionPercentage do PolicyOwner se você configurar.
             * Isso evita mandar 0 ou campos desnecessários.
             */
            $policyOwnerCommission = (float) config('services.pottencial.default_commission', 0.15);

            if ($policyOwnerCommission > 0) {
                $policyOwner['commissionPercentage'] = $policyOwnerCommission;
            }

            $agents[] = $policyOwner;
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
        return [
            $this->participant(
                role: 'PolicyHolder',
                document: $policyHolderDocument,
                contact: $this->leadContact($lead),
                main: true
            ),
        ];
    }

    private function participant(
        string $role,
        string $document,
        array $contact,
        bool $main = false
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

        return $participant;
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

    private function addressFromLead(Lead $lead): array
    {
        $endereco = $lead->endereco;

        return [
            'street' => $endereco?->logradouro ?? '',
            'number' => $endereco?->numero ?: 'S/N',
            'district' => $endereco?->bairro ?? '',
            'city' => $endereco?->cidade_imovel ?? '',
            'state' => strtoupper((string) ($endereco?->estado ?? '')),
            'zipCode' => \only_numbers($endereco?->cep ?? ''),
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
        /*
         * Não enviar OUTRAS_DESPESAS porque o exemplo da Pottencial
         * não mostra essa description como aceita.
         */
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
        ], fn (array $expense) => $expense['value'] > 0));
    }

    private function valorAgua(Lead $lead): float
    {
        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;
        $valorAgua = $this->expenseValue($lead, 'valor_agua');

        /*
         * Regra do seu TCC:
         * se água não for informada, usar 10% do aluguel.
         */
        return $valorAgua ?? ($aluguel * 0.10);
    }

    private function valorLuz(Lead $lead): float
    {
        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;
        $valorLuz = $this->expenseValue($lead, 'valor_luz');

        /*
         * Regra do seu TCC:
         * se luz não for informada, usar 10% do aluguel.
         */
        return $valorLuz ?? ($aluguel * 0.10);
    }

    private function expenseValue(Lead $lead, string $field): ?float
    {
        $value = $lead->despesas?->{$field} ?? null;

        return $value !== null && $value !== '' ? (float) $value : null;
    }

    private function validateRequiredLeadData(Lead $lead): void
    {
        if (!filled($lead->nome)) {
            throw new \RuntimeException('Nome do solicitante não informado para envio da cotação.');
        }

        if (!filled($lead->email)) {
            throw new \RuntimeException('E-mail do solicitante não informado para envio da cotação.');
        }

        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;

        if ($aluguel <= 0) {
            throw new \RuntimeException('Valor do aluguel não informado para envio da cotação.');
        }

        $endereco = $lead->endereco;

        if (!$endereco) {
            throw new \RuntimeException('Endereço do imóvel não encontrado para envio da cotação.');
        }

        $requiredAddressFields = [
            'logradouro' => 'logradouro',
            'bairro' => 'bairro',
            'cidade_imovel' => 'cidade',
            'estado' => 'estado',
            'cep' => 'CEP',
        ];

        foreach ($requiredAddressFields as $field => $label) {
            if (!filled($endereco->{$field} ?? null)) {
                throw new \RuntimeException("Campo de endereço obrigatório ausente: {$label}.");
            }
        }
    }



    private function dateValue(mixed $value): Carbon
    {
        return $value instanceof Carbon
            ? $value
            : Carbon::parse($value);
    }
}
