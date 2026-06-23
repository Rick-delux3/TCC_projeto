<?php

namespace App\Services\Insurance\Payloads;

use App\Models\InsuranceAnalysis;
use App\Models\Lead;
use Illuminate\Support\Carbon;
use App\Services\CpfLookupService;

class TooRentalGuaranteePayloadBuilder
{
    public function __construct(
        private CpfLookupService $cpfLookupService
    )
    {}
    /**
     * Monta o payload para:
     *
     * POST /fianca/proposta/ficha
     *
     * Esse é o primeiro payload da Too.
     * Ele cria a ficha/proposta de seguro fiança.
     */
    public function buildFichaPayload(InsuranceAnalysis $analysis): array
    {
        $this->loadRelations($analysis);

        $lead = $analysis->lead;

        if (!$lead) {
            throw new \RuntimeException('Lead não encontrado para montar payload da Too.');
        }

        $this->validateBaseLeadData($lead);

        return [
            'cnpjCorretor' => $this->brokerCnpj(),
            'razaoSocialCorretor' => $this->brokerName(),

            'pretendentes' => [
                [
                    'nome' => $lead->nome,
                    'nomeSocial' => null,
                    'cpf' => $this->onlyNumbers($lead->cpf),

                    /*
                     * A Too exige data de nascimento.
                     * Como o banco atual não possui esse campo,
                     * usamos valor padrão de sandbox via .env.
                     */
                    'dataNascimento' => $this->birthdateForToo($lead),

                    /*
                     * Para sandbox/TCC, consideramos que o solicitante:
                     * - residirá no imóvel;
                     * - será o responsável financeiro;
                     * - é o pretendente principal.
                     */
                    'residiraImovel' => $this->configBool('services.too.default_reside_property', true),
                    'responsavelFinanceiroPeloImovel' => $this->configBool('services.too.default_financial_responsible', true),

                    /*
                     * A Too exige renda, vínculo e profissão para quem responde
                     * financeiramente pelo imóvel.
                     *
                     * Como você não pode alterar o banco agora, esses dados
                     * ficam configurados no .env para sandbox.
                     */
                    'rendaFixaMensal' => $this->monthlyIncomeForToo($lead),
                    'vinculoEmpregaticio' => config('services.too.default_employment', 'Autônomo'),
                    'profissao' => mb_substr((string) config('services.too.default_profession', 'Autônomo'), 0, 40),

                    'principal' => true,
                ],
            ],

            'locacao' => [
                'finalidadeLocacao' => config('services.too.default_rental_purpose', 'Residencial'),
                'cep' => $this->onlyNumbers($lead->endereco?->cep),
                'logradouro' => $lead->endereco?->logradouro,
                'numero' => $lead->endereco?->numero ?: 'S/N',
                'complemento' => $lead->endereco?->complemento,
                'bairro' => $lead->endereco?->bairro,
                'cidade' => $lead->endereco?->cidade_imovel,
                'uf' => strtoupper((string) $lead->endereco?->estado),
            ],

            'coberturas' => $this->coverages($lead),
        ];
    }

    /**
     * Monta o payload para:
     *
     * POST /fianca/proposta/cotacao
     *
     * Esse payload deve ser usado depois que a ficha/proposta já existir.
     */
    public function buildQuotePayload(InsuranceAnalysis $analysis, string|int $numeroFicha): array
    {
        $this->loadRelations($analysis);

        $lead = $analysis->lead;

        if (!$lead) {
            throw new \RuntimeException('Lead não encontrado para montar payload de cotação da Too.');
        }

        $this->validateBaseLeadData($lead);

        $startDate = $this->leaseStartDate($analysis);
        $endDate = $this->leaseEndDate($analysis, $startDate);

        return [
            'cnpjCorretor' => $this->brokerCnpj(),
            'razaoSocialCorretor' => $this->brokerName(),

            /*
             * A Too pede numeroFicha na cotação.
             * Normalmente ele vem do retorno do endpoint:
             * POST /fianca/proposta/ficha
             */
            'numeroFicha' => $numeroFicha,

            /*
             * A collection usa formato Y/m/d.
             */
            'inicioVigenciaContratoLocacao' => $startDate->format('Y/m/d'),
            'finalVigenciaContratoLocacao' => $endDate->format('Y/m/d'),

            'indiceDeReajusteAluguel' => config('services.too.default_rent_adjustment_index', 'IPCA'),
            'periodoIndenitario' => (int) config('services.too.default_indemnity_period', 30),

            /*
             * Mantemos configurável porque algumas APIs interpretam comissão
             * como 0.10 e outras como 10. Se a Too recusar, basta ajustar no .env.
             */
            'percentualComissao' => (float) config('services.too.default_commission_percentage', 0.10),

            'coberturas' => $this->coverages($lead),
        ];
    }

    /**
     * Carrega os relacionamentos necessários.
     *
     * Mantive lead.company porque no seu projeto o relacionamento com
     * a imobiliária cadastrada ainda é usado assim.
     *
     * Não use lead.imobiliaria aqui, porque no seu banco esse campo pode ser string.
     */
    private function loadRelations(InsuranceAnalysis $analysis): void
    {
        $analysis->loadMissing([
            'lead.company',
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'lead.locador',
            'lead.imobiliariaInformada',
        ]);
    }

    /**
     * Coberturas no formato exigido pela Too.
     *
     * Regras da collection:
     * - valorAluguel obrigatório, mínimo 200.
     * - encargos opcionais, mas quando enviados, mínimo 15.
     * - soma aluguel + encargos não pode ultrapassar 24999.
     * - danos/multa/pintura podem ser aluguel ou zero.
     */
    private function coverages(Lead $lead): array
    {
        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;

        $agua = $this->valorAgua($lead);
        $luz = $this->valorLuz($lead);

        $coverages = [
            'valorAluguel' => $this->money($aluguel),

            'valorAgua' => $this->optionalCoverage($agua),
            'valorCondominio' => $this->optionalCoverage($this->expenseValue($lead, 'valor_condominio')),
            'valorGas' => $this->optionalCoverage($this->expenseValue($lead, 'valor_gas')),
            'valorIptu' => $this->optionalCoverage($this->expenseValue($lead, 'valor_iptu')),
            'valorLuz' => $this->optionalCoverage($luz),

            /*
             * A Too permite valores iguais ao aluguel ou zero.
             * Para sandbox, deixamos danos ao imóvel igual ao aluguel
             * e os demais zerados.
             */
            'valorDanosAoImovel' => $this->money($aluguel),
            'valorDanosAMoveis' => 0.0,
            'valorMultasContratuais' => 0.0,
            'valorPinturaExterna' => 0.0,
            'valorPinturaInterna' => 0.0,
        ];

        /*
         * Remove opcionais nulos, mas mantém zeros permitidos nas coberturas finais.
         */
        return array_filter($coverages, function ($value) {
            return $value !== null;
        });
    }

    /**
     * A Too exige que encargos opcionais tenham valor mínimo de 15,
     * quando forem enviados.
     */
    private function optionalCoverage(?float $value): ?float
    {
        if ($value === null || $value <= 0) {
            return null;
        }

        return $this->money(max($value, 15));
    }

    /**
     * Regra do seu TCC:
     * se água não for informada, usar 10% do aluguel.
     */
    private function valorAgua(Lead $lead): float
    {
        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;
        $valorAgua = $this->expenseValue($lead, 'valor_agua');

        return $valorAgua ?? ($aluguel * 0.10);
    }

    /**
     * Regra do seu TCC:
     * se luz não for informada, usar 10% do aluguel.
     */
    private function valorLuz(Lead $lead): float
    {
        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;
        $valorLuz = $this->expenseValue($lead, 'valor_luz');

        return $valorLuz ?? ($aluguel * 0.10);
    }

    private function expenseValue(Lead $lead, string $field): ?float
    {
        $value = $lead->despesas?->{$field} ?? $lead->{$field} ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function validateBaseLeadData(Lead $lead): void
    {
        if (!filled($lead->nome)) {
            throw new \RuntimeException('Nome do pretendente não informado para envio à Too.');
        }

        if (!filled($lead->cpf)) {
            throw new \RuntimeException('CPF do pretendente não informado para envio à Too.');
        }

        if (strlen($this->onlyNumbers($lead->cpf)) !== 11) {
            throw new \RuntimeException('CPF do pretendente deve conter 11 dígitos para envio à Too.');
        }

        if (!$lead->endereco) {
            throw new \RuntimeException('Endereço do imóvel não encontrado para envio à Too.');
        }

        $requiredAddressFields = [
            'cep' => 'CEP',
            'logradouro' => 'logradouro',
            'bairro' => 'bairro',
            'cidade_imovel' => 'cidade',
            'estado' => 'UF',
        ];

        foreach ($requiredAddressFields as $field => $label) {
            if (!filled($lead->endereco->{$field} ?? null)) {
                throw new \RuntimeException("Campo obrigatório ausente para Too: {$label}.");
            }
        }

        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;

        if ($aluguel < 200) {
            throw new \RuntimeException('Valor do aluguel para Too deve ser no mínimo 200.');
        }

        $rendaMensal = $this->monthlyIncomeForToo($lead);

        if ($rendaMensal <= 0) {
            throw new \RuntimeException('Renda fixa mensal inválida para envio à Too.');
        }

        $total = $aluguel
            + ($this->expenseValue($lead, 'valor_condominio') ?? 0)
            + ($this->expenseValue($lead, 'valor_iptu') ?? 0)
            + ($this->expenseValue($lead, 'valor_gas') ?? 0)
            + $this->valorAgua($lead)
            + $this->valorLuz($lead);

        if ($total > 24999) {
            throw new \RuntimeException('A soma de aluguel + encargos não pode ultrapassar 24999 para a Too.');
        }

        if (!$this->brokerCnpj()) {
            throw new \RuntimeException('TOO_BROKER_CNPJ não configurado.');
        }

        if (!$this->brokerName()) {
            throw new \RuntimeException('TOO_BROKER_NAME não configurado.');
        }
    }

    private function leaseStartDate(InsuranceAnalysis $analysis): Carbon
    {
        if ($analysis->lease_start_date) {
            return Carbon::parse($analysis->lease_start_date);
        }

        return now();
    }

    private function leaseEndDate(InsuranceAnalysis $analysis, Carbon $startDate): Carbon
    {
        if ($analysis->lease_end_date) {
            return Carbon::parse($analysis->lease_end_date);
        }

        /*
         * Regra do seu TCC:
         * contrato de 30 meses.
         */
        return $startDate->copy()->addMonthsNoOverflow(30);
    }

    private function brokerCnpj(): string
    {
        return $this->onlyNumbers(config('services.too.broker_cnpj'));
    }

    private function brokerName(): string
    {
        return trim((string) config('services.too.broker_name'));
    }

    private function onlyNumbers(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    private function money(float|int|null $value): float
    {
        return round((float) $value, 2);
    }

    private function configBool(string $key, bool $default): bool
    {
        $value = config($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
    
    private function monthlyIncomeForToo(Lead $lead): float
    {
        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;

        return $this->money($aluguel * 4);
    }
    /**
 * Busca a data de nascimento do pretendente usando o CpfLookupService.
 *
 * No ambiente de testes, se a API falhar, pode usar fallback.
 * Em produção, o ideal é configurar CPF_LOOKUP_FAILS_ANALYSIS=true
 * para não enviar data falsa para a seguradora.
 */
    private function birthdateForToo(Lead $lead): string
    {
        $birthdate = $this->cpfLookupService->birthdateForToo($lead->cpf);

        if ($birthdate) {
            return $birthdate;
        }

        if (config('services.cpf_lookup.fail_analysis_if_missing_birthdate', false)) {
            throw new \RuntimeException('Não foi possível obter a data de nascimento do CPF para envio à Too.');
        }

        return config('services.cpf_lookup.fallback_birthdate', '1985/12/10');
    }
}