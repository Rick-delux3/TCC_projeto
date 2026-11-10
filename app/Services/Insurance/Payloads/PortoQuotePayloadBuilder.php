<?php

namespace App\Services\Insurance\Payloads;

use App\Exceptions\InvalidInsuranceAnalysisPayload;
use App\Models\InsuranceQuote;
use App\Models\Lead;
use App\Rules\CpfOrCnpj;
use Illuminate\Support\Facades\Validator;

/**
 * Monta os payloads da Porto Seguro (Aluguel Essencial).
 *
 * - build(): POST /orcamentos — uma criação por cenário de pretendentes.
 * - commissionUpdate(): PUT /orcamentos/{orcamento}/{susep} — um payload
 *   mínimo e independente por oferta, somente com a comissão.
 *
 * Os dados vêm do lead restaurado do snapshot da rodada e das datas
 * congeladas na cotação; nada é consultado em APIs externas aqui.
 */
class PortoQuotePayloadBuilder
{
    private const RESIDENTIAL = '1';

    private const COMMERCIAL = '2';

    private const RESIDENTIAL_COMPANY_REASON = 'A';

    /** Encargos opcionais: código Porto => campo de despesas do lead. */
    private const CHARGES = [
        '002' => 'valor_iptu',
        '003' => 'valor_condominio',
        '004' => 'valor_agua',
        '005' => 'valor_luz',
        '006' => 'valor_gas',
    ];

    /** Danos, pintura interna, pintura externa e multa: IS igual ao aluguel. */
    private const RENT_BASED_COVERAGES = ['007', '008', '009', '010'];

    public function build(InsuranceQuote $quote): array
    {
        $lead = $quote->lead;
        if (! $lead) {
            $this->invalid('Lead não encontrado para montar o orçamento da Porto.');
        }

        $purpose = $lead->tipo_locacao?->value;
        if (! in_array($purpose, ['residencial', 'comercial'], true)) {
            $this->invalid('Informe a finalidade residencial ou comercial da locação para a Porto.');
        }

        $document = $this->document($lead->rentalApplicantDocument());
        $isCompany = strlen($document) === 14;
        $commercial = $purpose === 'comercial';
        $rentCents = $this->rentCents($lead);

        // CNPJ sempre é enquadrado como PFCOM na Porto, inclusive o residencial.
        $usesCommercialFrame = $commercial || $isCompany;
        $incomeCents = $rentCents * ($usesCommercialFrame ? 10 : 4);

        $payload = [
            'susep' => $this->susep(),
            'cpfCotador' => $this->quoterCpf(),
            'cep' => $this->cep($lead),
            'tipoLocacao' => $usesCommercialFrame ? self::COMMERCIAL : self::RESIDENTIAL,
            'locatarios' => $this->tenants($quote, $lead, $document, $incomeCents),
            'coberturas' => $this->coverages($lead, $rentCents),
            'contrato' => $this->contract($quote),
        ];

        if (($partnerCode = $this->partnerCode($lead)) !== null) {
            $payload['imobiliaria'] = $partnerCode;
        }

        if ($usesCommercialFrame) {
            $payload['locacaoComercial'] = $this->commercialLease($lead, $isCompany, $commercial, $rentCents);
        }

        return $payload;
    }

    /**
     * Payload mínimo do ajuste de comissão de uma única oferta.
     *
     * Cada chamada devolve um array novo, sem estado compartilhado, para que
     * os PUTs simultâneos nunca troquem o produto entre si.
     */
    public function commissionUpdate(string $offerCode): array
    {
        if (preg_match('/^[0-9]{4}$/D', $offerCode) !== 1) {
            $this->invalid('Código da oferta Porto inválido para o ajuste de comissão.');
        }

        return ['ofertas' => [[
            'seguro' => $offerCode,
            'comissao' => ['codComissao' => $this->commission()],
        ]]];
    }

    /**
     * Código Porto da imobiliária, somente para origem cadastrada com código
     * associado. Sem código o orçamento segue sem o campo e a cotação usa CC.
     */
    public function partnerCode(Lead $lead): ?string
    {
        if ($lead->tipo_solicitante !== 'imobiliaria_cadastrada') {
            return null;
        }

        $code = $lead->imobiliariaVinculada?->getAttribute('porto_partner_code');

        return is_string($code) && preg_match('/^[0-9]{6}$/D', $code) === 1 ? $code : null;
    }

    private function tenants(InsuranceQuote $quote, Lead $lead, string $document, int $incomeCents): array
    {
        // Contatos sempre do pretendente principal, inclusive para o cônjuge.
        $contact = ['email' => $this->email($lead), 'telefoneCelular' => $this->cellPhone($lead)];
        $income = ['dadosProfissionais' => ['totalRendimentos' => $this->amount($incomeCents)]];

        $tenants = [[
            'cpfCnpj' => $document,
            'nome' => $this->name($lead->nome),
            'dataNascimento' => $this->birthdate($lead->getAttributes()['data_nascimento'] ?? null),
            'titular' => 'S',
        ] + $contact + $income];

        if ($quote->scenario?->kind === 'joint') {
            if (strlen($document) !== 11) {
                $this->invalid('A análise conjunta da Porto existe somente para pessoa física.');
            }
            $spouse = $lead->conjuge;
            if (! $spouse) {
                $this->invalid('Cônjuge não encontrado para a análise conjunta da Porto.');
            }
            $spouseDocument = $this->document((string) CpfOrCnpj::normalize($spouse->cpf ?? ''));
            if (strlen($spouseDocument) !== 11 || $spouseDocument === $document) {
                $this->invalid('CPF do cônjuge inválido para a análise conjunta da Porto.');
            }
            $tenants[] = [
                'cpfCnpj' => $spouseDocument,
                'nome' => $this->name($spouse->nome),
                'dataNascimento' => $this->birthdate($spouse->getAttributes()['data_nascimento'] ?? null),
                'titular' => 'N',
            ] + $contact + $income;
        }

        return $tenants;
    }

    private function coverages(Lead $lead, int $rentCents): array
    {
        $coverages = [['codCobertura' => '001', 'valorIS' => $this->amount($rentCents)]];

        // Formulário do locatário envia exclusivamente o aluguel.
        if ($lead->tipo_solicitante === 'locatario') {
            return $coverages;
        }

        foreach (self::CHARGES as $code => $field) {
            $cents = $this->cents($lead->despesas?->getAttribute($field), 'Encargos do imóvel inválidos para a Porto.');
            if ($cents === null || $cents <= 0) {
                continue;
            }
            if ($cents > $rentCents) {
                $this->invalid('A Porto não aceita encargo com valor superior ao aluguel. Revise as despesas informadas.');
            }
            $coverages[] = ['codCobertura' => $code, 'valorIS' => $this->amount($cents)];
        }

        foreach (self::RENT_BASED_COVERAGES as $code) {
            $coverages[] = ['codCobertura' => $code, 'valorIS' => $this->amount($rentCents)];
        }

        return $coverages;
    }

    private function contract(InsuranceQuote $quote): array
    {
        $start = $quote->lease_start_date;
        $end = $quote->lease_end_date;
        if (! $start || ! $end || ! $end->greaterThan($start)) {
            $this->invalid('Vigência da rodada não definida para a Porto.');
        }

        $validityType = (string) config('services.porto.contract_validity_type', '1');
        $adjustment = (string) config('services.porto.rent_adjustment_code', '002');
        if (! in_array($validityType, ['0', '1', '2'], true) || preg_match('/^0(0[1-9]|10)$/D', $adjustment) !== 1) {
            $this->invalid('Configuração de vigência ou reajuste da Porto inválida.');
        }

        return [
            'tipoVigenciaContrato' => $validityType,
            'inicioContrato' => $start->format('d/m/Y'),
            'fimContrato' => $end->format('d/m/Y'),
            'inicioSeguro' => $start->format('d/m/Y'),
            'fimSeguro' => $end->format('d/m/Y'),
            'codigoReajuste' => $adjustment,
        ];
    }

    private function commercialLease(Lead $lead, bool $isCompany, bool $commercial, int $rentCents): array
    {
        if ($commercial) {
            $reason = (string) $lead->motivo_contratacao;
            if (preg_match('/^[1-9]$/D', $reason) !== 1) {
                $this->invalid('Selecione o motivo da contratação da locação comercial antes de cotar na Porto.');
            }
        } else {
            // CNPJ residencial: motivo fixado no servidor, nunca vindo do formulário.
            $reason = self::RESIDENTIAL_COMPANY_REASON;
        }

        $lease = [];
        if ($isCompany) {
            $capital = config('services.porto.company_share_capital', 450000);
            if (! is_int($capital) || $capital < 0 || $capital > 99999999) {
                $this->invalid('Capital social padrão da Porto inválido.');
            }
            $lease['capitalSocial'] = $capital;
        }
        // 100 × aluguel em reais é, numericamente, o próprio aluguel em centavos:
        // sempre inteiro e dentro do limite da Porto (aluguel máximo de R$ 10.000).
        $lease['capitalAberturaNegocio'] = $rentCents;
        $lease['motivoLocacao'] = $reason;

        return $lease;
    }

    private function rentCents(Lead $lead): int
    {
        $cents = $this->cents($lead->despesas?->valor_aluguel, 'Informe um valor de aluguel válido para a Porto.');
        if ($cents === null || $cents < 20000 || $cents > 1000000) {
            $this->invalid('O aluguel para a Porto deve estar entre R$ 200,00 e R$ 10.000,00.');
        }

        return $cents;
    }

    /** Converte um valor monetário em centavos inteiros, sem perder precisão. */
    private function cents(mixed $value, string $message): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
            $this->invalid($message);
        }

        return (int) round((float) $value * 100);
    }

    /** Inteiro quando não há centavos; decimal com duas casas caso contrário. */
    private function amount(int $cents): int|float
    {
        return $cents % 100 === 0 ? intdiv($cents, 100) : round($cents / 100, 2);
    }

    private function document(string $document): string
    {
        if (Validator::make(['document' => $document], ['document' => ['required', new CpfOrCnpj]])->fails()) {
            $this->invalid('CPF/CNPJ do pretendente inválido para a Porto.');
        }

        return $document;
    }

    private function name(mixed $name): string
    {
        $name = is_string($name) ? trim(preg_replace('/\s+/u', ' ', $name)) : '';
        $length = mb_strlen($name);
        if ($length < 5 || $length > 60) {
            $this->invalid('Nome ou razão social do pretendente deve ter entre 5 e 60 caracteres para a Porto.');
        }

        return $name;
    }

    private function birthdate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }
        $value = is_string($value) ? substr($value, 0, 10) : null;
        if (Validator::make(['date' => $value], ['date' => ['required', 'date_format:Y-m-d', 'before:today']])->fails()) {
            $this->invalid('Informe uma data de nascimento válida do pretendente/responsável antes de cotar na Porto.');
        }

        return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $value)->format('d/m/Y');
    }

    private function email(Lead $lead): string
    {
        $email = is_string($lead->email) ? trim($lead->email) : '';
        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:64']])->fails()) {
            $this->invalid('E-mail do pretendente inválido para a Porto.');
        }

        return $email;
    }

    private function cellPhone(Lead $lead): string
    {
        $phone = \only_numbers(is_string($lead->tel) ? $lead->tel : null);
        if (preg_match('/^[0-9]{10,11}$/D', $phone) !== 1) {
            $this->invalid('Telefone do pretendente inválido para a Porto. Informe DDD e número.');
        }

        return $phone;
    }

    private function cep(Lead $lead): string
    {
        $cep = \only_numbers($lead->endereco?->cep);
        if (preg_match('/^[0-9]{8}$/D', $cep) !== 1) {
            $this->invalid('CEP do imóvel inválido para a Porto.');
        }

        return $cep;
    }

    private function susep(): string
    {
        $susep = config('services.porto.susep');
        $susep = is_string($susep) ? strtoupper(trim($susep)) : '';
        if (preg_match('/^[A-Z0-9]{5}[FJ]$/D', $susep) !== 1) {
            $this->invalid('PORTO_SUSEP ausente ou inválido. Confira o .env e o cache de configuração.');
        }

        return $susep;
    }

    private function quoterCpf(): string
    {
        $cpf = config('services.porto.cpf_cotador');
        $cpf = (string) CpfOrCnpj::normalize(is_string($cpf) ? trim($cpf) : '');
        if (strlen($cpf) !== 11 || Validator::make(['cpf' => $cpf], ['cpf' => [new CpfOrCnpj]])->fails()) {
            $this->invalid('CPF_COTADOR ausente ou inválido. Confira o .env e o cache de configuração.');
        }

        return $cpf;
    }

    private function commission(): int
    {
        $commission = config('services.porto.commission', 15);
        if (! is_int($commission) || $commission < 0 || $commission > 100) {
            $this->invalid('Comissão da Porto deve ser um inteiro em pontos percentuais (15 para 15%).');
        }

        return $commission;
    }

    private function invalid(string $message): never
    {
        throw new InvalidInsuranceAnalysisPayload($message);
    }
}
