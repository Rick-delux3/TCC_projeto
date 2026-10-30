<?php

namespace App\Services\Insurance\Payloads;

use App\Enums\TipoLocacao;
use App\Models\InsuranceExecution;
use App\Models\Lead;
use App\Rules\CpfOrCnpj;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class TooRentalGuaranteePayloadBuilder
{
    /**
     * Monta o payload para:
     *
     * POST /fianca/proposta/ficha
     *
     * Esse é o primeiro payload da Too.
     * Ele cria a ficha/proposta de seguro fiança.
     */
    public function buildFichaPayload(InsuranceExecution $analysis): array
    {
        $this->loadRelations($analysis);

        $lead = $analysis->lead;

        if (! $lead) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Lead não encontrado para montar payload da Too.');
        }

        $this->validateBaseLeadData($lead);

        return [
            'cnpjCorretor' => $this->brokerCnpj(),
            'razaoSocialCorretor' => $this->brokerName(),

            'pretendentes' => $this->quoteApplicants($analysis),

            'locacao' => $this->locacaoPayload($lead),

            'coberturas' => $this->coverages($lead),
        ];
    }

    public function buildBasicDataPayload(InsuranceExecution $analysis): array
    {
        $this->loadRelations($analysis);

        $lead = $analysis->lead;

        if (! $lead) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Lead não encontrado para montar payload de atualização de dados básicos da Too.');
        }

        $this->validateBaseLeadData($lead);

        return [

            'pretendentes' => $this->quoteApplicants($analysis),

            'locacao' => $this->locacaoPayload($lead),

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
    public function buildQuotePayload(InsuranceExecution $analysis, string|int $numeroFicha): array
    {
        $this->loadRelations($analysis);

        $lead = $analysis->lead;

        if (! $lead) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Lead não encontrado para montar payload de cotação da Too.');
        }

        $this->validateBaseLeadData($lead);

        $startDate = $this->leaseStartDate($analysis);
        $endDate = $this->leaseEndDate($analysis, $startDate);

        $this->validateQuoteSettings();
        $numeroFicha = $this->proposalNumber($numeroFicha);

        return [
            'cnpjCorretor' => $this->brokerCnpj(),
            'razaoSocialCorretor' => $this->brokerName(),

            /*
             * A Too pede numeroFicha na cotação.
             * Normalmente ele vem do retorno do endpoint:
             * POST /fianca/proposta/ficha
             */
            'numeroFicha' => $numeroFicha,

            'inicioVigenciaContratoLocacao' => $startDate->format('Y-m-d'),
            'finalVigenciaContratoLocacao' => $endDate->format('Y-m-d'),

            'indiceDeReajusteAluguel' => config('services.too.default_rent_adjustment_index', 'IGP_M'),
            'periodoIndenitario' => (int) config('services.too.default_indemnity_period', 30),

            'percentualComissao' => $this->commissionPercentage(),

            'coberturas' => $this->coverages($lead),
        ];
    }

    public function buildQuotePdfPayload(InsuranceExecution $analysis, string|int $numeroFicha): array
    {
        $analysis->loadMissing('lead');
        $lead = $analysis->lead;
        if (! $lead || ($lead->tipo_solicitante === 'imobiliaria_cadastrada' && ! $lead->company_id)) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Lead ou vínculo da imobiliária ausente para o PDF da Too.');
        }

        return [
            'numeroFicha' => $this->proposalNumber($numeroFicha),
            'formaPagamento' => [
                'tipoPagamento' => $lead->tipo_solicitante === 'imobiliaria_cadastrada' ? 2 : 1,
                'tipoEntrada' => 2,
                'opcaoParcelamento' => [['tipoOpcaoParcelamento' => 2, 'quantidadeParcelas' => 30]],
            ],
        ];
    }

    private function proposalNumber(string|int $number): int
    {
        $number = filter_var($number, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($number === false) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Número da ficha inválido para envio à Too.');
        }

        return $number;
    }

    private function validateQuoteSettings(): void
    {
        if (config('services.too.default_rent_adjustment_index', 'IGP_M') !== 'IGP_M'
            || (string) config('services.too.default_indemnity_period', 30) !== '30') {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('A cotação Too deve usar IGP_M e período indenitário de 30 meses.');
        }
    }

    private function commissionPercentage(): float
    {
        $commission = config('services.too.default_commission_percentage', 15.0);
        if (! is_numeric($commission) || ! is_finite((float) $commission) || $commission < 10 || $commission > 40) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Comissão Too deve estar entre 10 e 40, em pontos percentuais (15.0 para 15%).');
        }

        return (float) $commission;
    }

    private function pretendentePayload(Lead $lead): array
    {
        return [
            'nome' => $lead->rentalApplicantNameForCpf(),
            'nomeSocial' => null,
            'cpf' => $lead->rentalApplicantCpf(),
            'dataNascimento' => $this->birthdateForToo($lead),

            'residiraImovel' => $lead->tipo_locacao === TipoLocacao::RESIDENCIAL
                && $this->configBool('services.too.default_reside_property', true),
            'responsavelFinanceiroPeloImovel' => $this->configBool('services.too.default_financial_responsible', true),

            'rendaFixaMensal' => $this->monthlyIncomeForToo($lead),
            'vinculoEmpregaticio' => $this->employmentTypeForToo(),
            'profissao' => $this->professionForToo(),

            'principal' => true,
        ];
    }

    private function quoteApplicants(InsuranceExecution $analysis): array
    {
        $applicants = [$this->pretendentePayload($analysis->lead)];
        if ($analysis instanceof \App\Models\InsuranceQuote && $analysis->scenario->kind === 'joint') {
            $spouse = app(\App\Services\Insurance\InsuranceLeadSnapshot::class)->restore(
                $analysis->scenario->providerRun->round->lead_snapshot, 'spouse',
            );
            $this->validateBaseLeadData($spouse);
            $applicants[] = array_replace($this->pretendentePayload($spouse), ['principal' => false]);
        }

        return $applicants;
    }

    private function locacaoPayload(Lead $lead): array
    {
        return [
            'finalidadeLocacao' => match ($lead->tipo_locacao) {
                TipoLocacao::RESIDENCIAL => 'Residencial',
                TipoLocacao::COMERCIAL => 'Comercial',
                default => throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Informe a finalidade residencial ou comercial da locação para a Too.'),
            },
            'cep' => $this->onlyNumbers($lead->endereco?->cep),
            'logradouro' => $lead->endereco?->logradouro,
            'numero' => $lead->endereco?->numero ?: 'S/N',
            'complemento' => $lead->endereco?->complemento,
            'bairro' => $lead->endereco?->bairro,
            'cidade' => $lead->endereco?->cidade_imovel,
            'uf' => strtoupper((string) $lead->endereco?->estado),
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
    private function loadRelations(InsuranceExecution $analysis): void
    {
        $analysis->loadMissing([
            'lead.lead_empresa',
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

        if ($lead->tipo_solicitante === 'locatario') {
            return ['valorAluguel' => $this->money($aluguel)];
        }

        $agua = $this->valorAgua($lead);
        $luz = $this->valorLuz($lead);

        $coverages = [
            'valorAluguel' => $this->money($aluguel),

            'valorAgua' => $this->optionalCoverage($agua),
            'valorCondominio' => $this->optionalCoverage($this->expenseValue($lead, 'valor_condominio')),
            'valorGas' => $this->optionalCoverage($this->expenseValue($lead, 'valor_gas')),
            'valorIptu' => $this->optionalCoverage($this->expenseValue($lead, 'valor_iptu')),
            'valorLuz' => $this->optionalCoverage($luz),

            'valorDanosAoImovel' => $this->money($aluguel),
            'valorDanosAMoveis' => 0.0,
            'valorMultasContratuais' => $this->money($aluguel),
            'valorPinturaExterna' => $this->money($aluguel),
            'valorPinturaInterna' => $this->money($aluguel),
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

        if ($value < 15) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Encargos opcionais da Too devem ser zero ou pelo menos 15. Corrija os valores informados.');
        }

        return $this->money($value);
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

        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Valor de despesa inválido para envio à Too.');
        }

        return (float) $value;
    }

    private function validateBaseLeadData(Lead $lead): void
    {
        if (! filled($lead->rentalApplicantNameForCpf())) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Nome do pretendente não informado para envio à Too.');
        }

        if (Validator::make(
            ['cpf' => $lead->rentalApplicantCpf()],
            ['cpf' => ['bail', 'required', 'string', 'size:11', new CpfOrCnpj]],
        )->fails()) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('CPF do pretendente/responsável inválido para envio à Too.');
        }

        $this->birthdateForToo($lead);
        $this->locacaoPayload($lead);
        $this->employmentTypeForToo();
        $this->professionForToo();
        $this->coverages($lead);

        if (! $lead->endereco) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Endereço do imóvel não encontrado para envio à Too.');
        }

        if (! filled($lead->endereco?->numero)) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Número do imóvel não informado para envio à Too.');
        }

        $requiredAddressFields = [
            'cep' => 'CEP',
            'logradouro' => 'logradouro',
            'bairro' => 'bairro',
            'cidade_imovel' => 'cidade',
            'estado' => 'UF',
            'numero' => 'numero',
        ];

        foreach ($requiredAddressFields as $field => $label) {
            if (! filled($lead->endereco->{$field} ?? null)) {
                throw new \App\Exceptions\InvalidInsuranceAnalysisPayload("Campo obrigatório ausente para Too: {$label}.");
            }
        }

        $aluguel = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;

        if ($aluguel < 200) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Valor do aluguel para Too deve ser no mínimo 200.');
        }

        $rendaMensal = $this->monthlyIncomeForToo($lead);

        if ($rendaMensal <= 0) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Renda fixa mensal inválida para envio à Too.');
        }

        $total = array_sum(\Illuminate\Support\Arr::only($this->coverages($lead), [
            'valorAluguel', 'valorCondominio', 'valorIptu', 'valorGas', 'valorAgua', 'valorLuz',
        ]));

        if ($total > 24999) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('A soma de aluguel + encargos não pode ultrapassar 24999 para a Too.');
        }

        if (! $this->brokerCnpj()) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('TOO_BROKER_CNPJ não configurado.');
        }

        if (! $this->brokerName()) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('TOO_BROKER_NAME não configurado.');
        }
    }

    private function leaseStartDate(InsuranceExecution $analysis): Carbon
    {
        if ($analysis->lease_start_date) {
            return Carbon::parse($analysis->lease_start_date);
        }

        return now();
    }

    private function leaseEndDate(InsuranceExecution $analysis, Carbon $startDate): Carbon
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

    private function birthdateForToo(Lead $lead): string
    {
        $birthdate = $lead->getAttributes()['data_nascimento'] ?? null;

        if ($birthdate instanceof \DateTimeInterface) {
            $birthdate = $birthdate->format('Y-m-d');
        }

        if (is_string($birthdate) && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $birthdate)) {
            $birthdate = substr($birthdate, 0, 10);
        }

        if (Validator::make(
            ['data_nascimento' => $birthdate],
            ['data_nascimento' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today']],
        )->fails()) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Informe uma data de nascimento válida do pretendente/responsável antes de enviar à Too.');
        }

        return $birthdate;
    }

    private function employmentTypeForToo(): string
    {
        $value = (string) config('services.too.default_employment', 'Clt');

        $normalized = $this->normalizeEnumValue($value);

        $map = [
            'clt' => 'Clt',
            'carteiraassinada' => 'Clt',

            'autonomo' => 'Autonomo',
            'autonomo' => 'Autonomo',

            'empresario' => 'Empresario',

            'funcionariopublico' => 'FuncionarioPublico',
            'servidorpublico' => 'FuncionarioPublico',

            'aposentado' => 'Aposentado',

            'rendaprovenientealuguel' => 'RendaProvenienteAluguel',
            'rendadealuguel' => 'RendaProvenienteAluguel',

            'estudante' => 'Estudante',
        ];

        if (! isset($map[$normalized])) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload(
                "Vínculo empregatício inválido para Too: {$value}. Use Clt, Autonomo, Empresario, Aposentado ou outro valor aceito."
            );
        }

        return $map[$normalized];
    }

    private function professionForToo(): string
    {
        $profession = (string) config('services.too.default_profession', 'Analista');

        $profession = trim($profession);

        if ($profession === '' || mb_strlen($profession) > 40) {
            throw new \App\Exceptions\InvalidInsuranceAnalysisPayload('Configure uma profissão válida para a Too, com até 40 caracteres.');
        }

        return $profession;
    }

    private function normalizeEnumValue(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        $from = [
            'á', 'à', 'ã', 'â',
            'é', 'ê',
            'í',
            'ó', 'ô', 'õ',
            'ú',
            'ç',
        ];

        $to = [
            'a', 'a', 'a', 'a',
            'e', 'e',
            'i',
            'o', 'o', 'o',
            'u',
            'c',
        ];

        $value = str_replace($from, $to, $value);

        return preg_replace('/[^a-z0-9]/', '', $value);
    }
}
