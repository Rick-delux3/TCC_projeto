<?php

namespace App\Services\Insurance\Payloads;

class TokioQuotePayloadBuilder
{
    public function build(\App\Models\InsuranceQuote $quote): array
    {
        $lead = $quote->lead;
        $snapshot = $quote->scenario->providerRun->round->lead_snapshot;
        $commercial = ($lead->tipo_locacao?->value ?? '') === 'comercial';
        if (! in_array($lead->tipo_locacao?->value, ['residencial', 'comercial'], true) || strlen($lead->rentalApplicantDocument()) !== 11) {
            $this->invalid('A Tokio exige pretendente PF e finalidade residencial ou comercial válida.');
        }
        $rent = $lead->despesas?->valor_aluguel;
        if (! is_numeric($rent) || ! is_finite((float) $rent) || (float) $rent <= 0) {
            $this->invalid('Informe um valor de aluguel válido para a Tokio.');
        }
        $rent = round((float) $rent, 2);
        $address = $lead->endereco;
        $location = [
            'cep' => \only_numbers($address?->cep), 'endereco' => $address?->logradouro,
            'numero' => $address?->numero, 'bairro' => $address?->bairro,
        ];
        $validator = \Illuminate\Support\Facades\Validator::make($location, [
            'cep' => ['required', 'digits:8'], 'endereco' => ['required', 'string'],
            'numero' => ['required'], 'bairro' => ['required', 'string'],
        ]);
        if ($validator->fails()) {
            $this->invalid('Endereço do imóvel incompleto para a Tokio. Confira CEP, logradouro, número e bairro.');
        }
        if (! $quote->lease_start_date || ! $quote->lease_end_date) {
            $this->invalid('Vigência da rodada não definida para a Tokio.');
        }
        $location['numero'] = (string) $location['numero'];
        if (filled($address?->complemento)) {
            $location['complemento'] = $address->complemento;
        }
        $location += [
            'inicioVigenciaContrato' => $quote->lease_start_date->format('d/m/Y'),
            'fimVigenciaContrato' => $quote->lease_end_date->format('d/m/Y'),
            'inicioVigenciaSeguro' => $quote->lease_start_date->format('d/m/Y'),
            'fimVigenciaSeguro' => $quote->lease_end_date->format('d/m/Y'),
            'assistenciaDesejada' => (string) data_get($snapshot, 'tokio.parameters.assistance', '26203'), 'multiploLMI' => 30,
        ];
        $people = [];
        if ($commercial) {
            $cnae = data_get($snapshot, 'tokio.cnae');
            $branch = $lead->ramo_atividade?->value;
            if (! is_numeric($cnae) || ! in_array((int) $branch, [21204, 21205, 21206], true)) {
                $this->invalid('Selecione uma atividade e um ramo válidos no catálogo Tokio.');
            }
            $people[] = ['empresaConstituida' => 'N', 'CNAE' => (int) $cnae, 'ramoAtividade' => (int) $branch, 'capitalInicial' => 450000];
        }
        $hasSpouse = (bool) data_get($snapshot, 'tokio.has_spouse', false);
        $people[] = $this->person($lead->cpf, $lead->nome, true, $lead->estado_civil, $commercial, $hasSpouse, $rent, $quote->scenario->kind === 'joint');
        if ($quote->scenario->kind === 'joint') {
            $people[] = $this->person($lead->conjuge?->cpf, $lead->conjuge?->nome, false, $lead->estado_civil, $commercial, true, $rent, true);
        }
        $coverages = ['vlAluguel' => $rent];
        if ($lead->tipo_solicitante !== 'locatario') {
            $location['pinturaNova'] = 'S';
            foreach (['icDanosImovel', 'icPinturaInterna', 'icPinturaExterna', 'icMultaRescisao'] as $field) {
                $coverages[$field] = 'S';
            }
            foreach (['vlDespesasOrdCondominiais' => 'valor_condominio', 'vlIPTU' => 'valor_iptu', 'vlAgua' => 'valor_agua', 'vlLuz' => 'valor_luz', 'vlGasCanalizado' => 'valor_gas'] as $target => $source) {
                $value = $lead->despesas?->getAttribute($source);
                if ($value !== null && $value !== '') {
                    if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                        $this->invalid('Encargos do imóvel inválidos para a Tokio.');
                    }
                    $coverages[$target] = round((float) $value, 2);
                }
            }
        }
        $propertyType = (string) data_get($snapshot, 'tokio.parameters.tipo_imovel', config('services.tokio.tipo_imovel'));
        if (! ctype_digit($propertyType) || (int) $propertyType <= 0) {
            $this->invalid('Tipo de imóvel da Tokio inválido. Confira TIPO_IMOVEL_DEFAULT no .env.');
        }
        $payload = [
            'tpPessoa' => 'F', 'icResidencial' => $commercial ? 'N' : 'S',
            'dadosItem' => ['tipoImovel' => (int) $propertyType, 'motivoContratacao' => $commercial ? 21114 : 26470],
            'dadosLocacao' => $location, 'pretendentes' => $people, 'coberturas' => $coverages,
        ];
        foreach (['pcComissao' => 'commission', 'pcRemuneracao' => 'remuneration', 'pcDesconto' => 'discount', 'pcAgravo' => 'surcharge'] as $field => $setting) {
            $value = data_get($snapshot, 'tokio.parameters.'.$setting);
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 100) {
                $this->invalid('Percentuais da Tokio inválidos.');
            }
            $payload[$field] = (float) $value;
        }

        return $payload;
    }

    private function person(?string $cpf, ?string $name, bool $primary, mixed $civil, bool $commercial, bool $hasSpouse, float $rent, bool $joint): array
    {
        $cpf = \only_numbers($cpf);
        if (strlen($cpf) !== 11 || blank($name) || \Illuminate\Support\Facades\Validator::make(['cpf' => $cpf], ['cpf' => [new \App\Rules\CpfOrCnpj]])->fails()) {
            $this->invalid('Nome e CPF do pretendente são obrigatórios e devem ser válidos para a Tokio.');
        }
        $civil = $civil instanceof \BackedEnum ? $civil->value : $civil;
        $civil = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii((string) $civil));
        $code = $joint ? 18490 : match ($civil) {
            'solteiro', 'solteira' => 18485, 'casado', 'casada' => 18490,
            'uniao_estavel', 'uniao estavel' => $commercial ? 18490 : 18489,
            'divorciado', 'divorciada' => 18486, 'separado', 'separada' => 18488,
            'viuvo', 'viuva' => 21285, default => 19901,
        };
        $person = ['cpf' => $cpf, 'nome' => trim($name), 'pretendentePrincipal' => $primary ? 'S' : 'N',
            'estadoCivil' => (string) $code, 'respondeFinanceiramente' => 'S', 'rendaFixaMensal' => number_format($rent * 4, 2, '.', '')];
        if ($commercial) {
            $person += ['vinculoEmpresa' => 'S', 'tipoVinculo' => '21147', 'percentualParticipacao' => $hasSpouse ? 50 : 100];
        }

        return $person;
    }

    private function invalid(string $message): never
    {
        throw new \App\Exceptions\InvalidInsuranceAnalysisPayload($message);
    }
}
