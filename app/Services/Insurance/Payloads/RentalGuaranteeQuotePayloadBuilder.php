<?php

namespace App\Services\Insurance\Payloads;

use App\Enums\TipoLocacao;
use App\Models\InsuranceExecution;
use App\Models\Lead;
use App\Rules\CpfOrCnpj;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class RentalGuaranteeQuotePayloadBuilder
{
    public function build(InsuranceExecution $analysis): array
    {
        /*
         * Carrega os relacionamentos necessários para montar o payload.
         * O vínculo com a imobiliária cadastrada usa o relacionamento
         * imobiliariaVinculada() definido no model Lead.
         */
        $relations = [
            'lead.lead_empresa',
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'lead.locador',
            'lead.imobiliariaInformada',
        ];

        $relations[] = 'lead.imobiliariaVinculada';

        $analysis->loadMissing($relations);

        $lead = $analysis->lead;

        if (! $lead) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Lead não encontrado para montar o payload da Pottencial.');
        }

        $policyHolderDocument = $lead->rentalApplicantDocument();

        if (Validator::make(['document' => $policyHolderDocument], ['document' => ['required', new CpfOrCnpj]])->fails()) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('CPF/CNPJ do pretendente inválido para envio da cotação à Pottencial.');
        }

        $occupation = match ($lead->tipo_locacao) {
            TipoLocacao::RESIDENCIAL => 'Residencial',
            TipoLocacao::COMERCIAL => 'Commercial',
            default => throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Informe a finalidade residencial ou comercial da locação para a Pottencial.'),
        };

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
            'participants' => $analysis instanceof \App\Models\InsuranceQuote
                ? $this->quoteParticipants($analysis)
                : [['documentNumber' => $policyHolderDocument, 'role' => 'PolicyHolder']],

            /*
             * paymentConditions fica no nível raiz.
             */
            'paymentConditions' => [
                'paymentType' => $lead->tipo_solicitante === 'imobiliaria_cadastrada' ? 'Invoice' : 'Boleto',

                'installments' => $analysis->installments
                    ?? (int) config('services.pottencial.default_installments', 30),
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
                    'planKey' => $this->planKey($analysis),

                    'multiple' => $leaseMonths,
                    'occupation' => $occupation,
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

    private function commissionedAgents(InsuranceExecution $analysis): array
    {
        $lead = $analysis->lead;

        $brokerDocument = \only_numbers(config('services.pottencial.broker_document'));

        if (Validator::make(['document' => $brokerDocument], ['document' => ['required', new CpfOrCnpj]])->fails()) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('CPF/CNPJ do broker ausente ou inválido para envio da cotação.');
        }

        $commission = config('services.pottencial.default_commission', 0.15);
        if (Validator::make(['commission' => $commission], ['commission' => ['required', 'numeric', 'between:0,1', 'decimal:0,3']])->fails()) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Comissão da Pottencial deve ser uma fração entre 0 e 1, com no máximo três casas decimais.');
        }

        $agents = [
            [
                'documentNumber' => $brokerDocument,
                'role' => 'Broker',
                'commissionPercentage' => (float) $commission,
                'lead' => true,
                'participationPercentage' => 1,
            ],
        ];

        /*
         * PolicyOwner somente quando for imobiliária cadastrada.
         */
        $imobiliaria = $lead?->imobiliariaVinculada;

        $companyDocument = \only_numbers($imobiliaria?->cnpj ?? '');

        if ($lead?->tipo_solicitante === 'imobiliaria_cadastrada') {
            if (! $lead->company_id || Validator::make(['document' => $companyDocument], ['document' => ['required', new CpfOrCnpj]])->fails()) {
                throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Imobiliária cadastrada sem vínculo ou CPF/CNPJ válido para a Pottencial.');
            }
            $agents[] = [
                'documentNumber' => $companyDocument,
                'role' => 'PolicyOwner',
            ];
        }

        return $agents;
    }

    private function policyType(): string
    {
        return match (mb_strtolower((string) config('services.pottencial.default_policy_type', 'Unique'))) {
            'unique', 'unico' => 'Unique',
            'itembyitem' => 'ItemByItem',
            default => throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Tipo de apólice inválido para a Pottencial.'),
        };
    }

    private function planKey(InsuranceExecution $analysis): string
    {
        return match (mb_strtolower((string) ($analysis->plan_key ?? config('services.pottencial.default_plan_key', 'Traditional')))) {
            'basic' => 'Basic',
            'complete' => 'Complete',
            'traditional' => 'Traditional',
            default => throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Plano inválido para a Pottencial.'),
        };
    }

    private function quoteParticipants(\App\Models\InsuranceQuote $quote): array
    {
        $participants = [['documentNumber' => $quote->lead->rentalApplicantDocument(), 'role' => 'PolicyHolder']];
        if ($quote->scenario->kind === 'joint') {
            $document = CpfOrCnpj::normalize($quote->lead->conjuge?->cpf);
            if (Validator::make(['document' => $document], ['document' => ['required', new CpfOrCnpj]])->fails()) {
                throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('CPF do cônjuge inválido para a cotação conjunta.');
            }
            $participants[] = ['documentNumber' => $document, 'role' => 'PolicyHolder'];
        }

        return $participants;
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
            'type' => $lead->tipo_locacao === TipoLocacao::COMERCIAL ? 'Commercial' : 'Residential',
        ];
    }

    private function coverages(Lead $lead, int $months): array
    {
        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;
        if ($lead->tipo_solicitante === 'locatario') {
            return [['key' => 'basica', 'insuredAmount' => max($aluguel * $months, 12000)]];
        }
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
        if ($lead->tipo_solicitante === 'locatario') {
            return [['description' => 'VALOR_ALUGUEL', 'value' => $this->expenseValue($lead, 'valor_aluguel')]];
        }

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
        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;

        if ($aluguel <= 0) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Valor do aluguel não informado para envio da cotação.');
        }

        $endereco = $lead->endereco;

        if (! $endereco) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Endereço do imóvel não encontrado para envio da cotação.');
        }

        $requiredAddressFields = [
            'logradouro' => 'logradouro',
            'bairro' => 'bairro',
            'cidade_imovel' => 'cidade',
            'estado' => 'estado',
            'cep' => 'CEP',
        ];

        foreach ($requiredAddressFields as $field => $label) {
            if (! filled($endereco->{$field} ?? null)) {
                throw new \App\Exceptions\InvalidInsuranceAnalysisPayload("Campo de endereço obrigatório ausente: {$label}.");
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
