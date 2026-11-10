<?php

use App\Exceptions\InvalidInsuranceAnalysisPayload;
use App\Models\InsuranceApplicantScenario;
use App\Models\InsuranceQuote;
use App\Services\Insurance\InsuranceLeadSnapshot;
use App\Services\Insurance\Payloads\PortoQuotePayloadBuilder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.porto.susep' => 'ABC12J', 'services.porto.cpf_cotador' => '092.380.376-98']);
});

/**
 * Cotação em memória com o lead restaurado de um snapshot de rodada, como o
 * coordenador entrega ao builder. Nenhum registro é gravado no banco.
 */
function portoBuilderQuote(string $kind = 'primary', array $attributes = [], array $relations = []): InsuranceQuote
{
    $snapshot = [
        'attributes' => array_merge([
            'id' => 1, 'tipo_solicitante' => 'locador', 'cpf' => '09238037698', 'nome' => 'Pretendente de Teste',
            'data_nascimento' => '1990-05-10', 'tipo_locacao' => 'residencial', 'motivo_contratacao' => null,
            'estado_civil' => 'casado', 'email' => 'principal@example.test', 'tel' => '11987654321',
        ], $attributes),
        'relations' => array_merge([
            'lead_empresa' => null, 'company' => null, 'imobiliariaVinculada' => null,
            'endereco' => ['cep' => '01001-000', 'logradouro' => 'Rua de teste', 'numero' => '10', 'bairro' => 'Centro'],
            'despesas' => ['valor_aluguel' => '1500.00'],
            'conjuge' => ['nome' => 'Cônjuge de Teste', 'cpf' => '383.295.608-56', 'data_nascimento' => '1992-03-04 00:00:00'],
            'locador' => null, 'imobiliariaInformada' => null,
        ], $relations),
    ];

    return (new InsuranceQuote(['lease_start_date' => '2026-11-10', 'lease_end_date' => '2029-05-10']))
        ->setRelation('lead', app(InsuranceLeadSnapshot::class)->restore($snapshot, $kind))
        ->setRelation('scenario', new InsuranceApplicantScenario(['kind' => $kind]));
}

function portoPayload(string $kind = 'primary', array $attributes = [], array $relations = []): array
{
    return app(PortoQuotePayloadBuilder::class)->build(portoBuilderQuote($kind, $attributes, $relations));
}

it('builds the minimal residential budget for an individual tenant', function () {
    $payload = portoPayload('primary', ['tipo_solicitante' => 'locatario'], [
        'despesas' => ['valor_aluguel' => '1500.00', 'valor_condominio' => '300.00', 'valor_agua' => '80.00'],
    ]);

    expect($payload)->toBe([
        'susep' => 'ABC12J',
        'cpfCotador' => '09238037698',
        'cep' => '01001000',
        'tipoLocacao' => '1',
        'locatarios' => [[
            'cpfCnpj' => '09238037698',
            'nome' => 'Pretendente de Teste',
            'dataNascimento' => '10/05/1990',
            'titular' => 'S',
            'email' => 'principal@example.test',
            'telefoneCelular' => '11987654321',
            'dadosProfissionais' => ['totalRendimentos' => 6000],
        ]],
        'coberturas' => [['codCobertura' => '001', 'valorIS' => 1500]],
        'contrato' => [
            'tipoVigenciaContrato' => '1',
            'inicioContrato' => '10/11/2026',
            'fimContrato' => '10/05/2029',
            'inicioSeguro' => '10/11/2026',
            'fimSeguro' => '10/05/2029',
            'codigoReajuste' => '002',
        ],
    ]);
});

it('frames each document and purpose with the Porto rental type, income and commercial data', function (
    array $attributes, array $relations, string $type, int $income, ?array $commercial, string $document, string $name,
) {
    $payload = portoPayload('primary', $attributes, $relations);

    expect($payload['tipoLocacao'])->toBe($type);
    expect($payload['locatarios'])->toHaveCount(1);
    expect($payload['locatarios'][0])->toMatchArray([
        'cpfCnpj' => $document, 'nome' => $name, 'dataNascimento' => '10/05/1990', 'titular' => 'S',
        'dadosProfissionais' => ['totalRendimentos' => $income],
    ]);
    expect($payload['locacaoComercial'] ?? null)->toBe($commercial);
})->with([
    'CPF residencial' => [[], [], '1', 6000, null, '09238037698', 'Pretendente de Teste'],
    'CPF comercial' => [
        ['tipo_locacao' => 'comercial', 'motivo_contratacao' => '3'], [], '2', 15000,
        ['capitalAberturaNegocio' => 150000, 'motivoLocacao' => '3'], '09238037698', 'Pretendente de Teste',
    ],
    'CNPJ comercial' => [
        ['cpf' => null, 'nome' => 'Empresa de Teste LTDA', 'tipo_locacao' => 'comercial', 'motivo_contratacao' => '7'],
        ['lead_empresa' => ['cnpj' => '11.222.333/0001-81', 'cpf_responsavel' => '09238037698', 'nome_responsavel' => 'Responsável de Teste']],
        '2', 15000, ['capitalSocial' => 450000, 'capitalAberturaNegocio' => 150000, 'motivoLocacao' => '7'],
        '11222333000181', 'Empresa de Teste LTDA',
    ],
    'CNPJ residencial ignora motivo armazenado' => [
        ['cpf' => null, 'nome' => 'Empresa de Teste LTDA', 'motivo_contratacao' => '5'],
        ['lead_empresa' => ['cnpj' => '11222333000181', 'cpf_responsavel' => '09238037698', 'nome_responsavel' => 'Responsável de Teste']],
        '2', 15000, ['capitalSocial' => 450000, 'capitalAberturaNegocio' => 150000, 'motivoLocacao' => 'A'],
        '11222333000181', 'Empresa de Teste LTDA',
    ],
]);

it('never sends the company representative as a tenant', function () {
    $payload = portoPayload('primary', ['cpf' => null, 'nome' => 'Empresa de Teste LTDA'], [
        'lead_empresa' => ['cnpj' => '11222333000181', 'cpf_responsavel' => '09238037698', 'nome_responsavel' => 'Responsável de Teste'],
        'conjuge' => null,
    ]);

    expect(json_encode($payload['locatarios']))->not->toContain('Respons')
        ->and(array_column($payload['locatarios'], 'cpfCnpj'))->toBe(['11222333000181']);
});

it('sends only positive charges plus rent based coverages outside the tenant form', function () {
    $payload = portoPayload('primary', [], ['despesas' => [
        'valor_aluguel' => '1500.50', 'valor_iptu' => '120.35', 'valor_condominio' => '1500.50',
        'valor_agua' => '0.00', 'valor_luz' => null, 'valor_gas' => '0.01',
    ]]);

    expect($payload['coberturas'])->toBe([
        ['codCobertura' => '001', 'valorIS' => 1500.5],
        ['codCobertura' => '002', 'valorIS' => 120.35],
        ['codCobertura' => '003', 'valorIS' => 1500.5],
        ['codCobertura' => '006', 'valorIS' => 0.01],
        ['codCobertura' => '007', 'valorIS' => 1500.5],
        ['codCobertura' => '008', 'valorIS' => 1500.5],
        ['codCobertura' => '009', 'valorIS' => 1500.5],
        ['codCobertura' => '010', 'valorIS' => 1500.5],
    ]);
    expect($payload['locatarios'][0]['dadosProfissionais']['totalRendimentos'])->toBe(6002);
});

it('keeps each applicant identity and shares the primary contacts across scenarios', function () {
    $contacts = ['email' => 'principal@example.test', 'telefoneCelular' => '11987654321'];

    $spouse = portoPayload('spouse')['locatarios'];
    expect($spouse)->toHaveCount(1);
    expect($spouse[0])->toMatchArray($contacts + [
        'cpfCnpj' => '38329560856', 'nome' => 'Cônjuge de Teste', 'dataNascimento' => '04/03/1992', 'titular' => 'S',
    ]);

    $joint = portoPayload('joint')['locatarios'];
    expect(array_column($joint, 'titular'))->toBe(['S', 'N']);
    expect($joint[0])->toMatchArray($contacts + ['cpfCnpj' => '09238037698', 'dataNascimento' => '10/05/1990']);
    expect($joint[1])->toMatchArray($contacts + [
        'cpfCnpj' => '38329560856', 'nome' => 'Cônjuge de Teste', 'dataNascimento' => '04/03/1992',
        'dadosProfissionais' => ['totalRendimentos' => 6000],
    ]);

    expect(portoPayload('primary')['locatarios'])->toHaveCount(1);
});

it('sends the Porto partner code only for a registered real estate agency that has one', function (
    string $origin, ?array $company, ?string $expected,
) {
    $quote = portoBuilderQuote('primary', ['tipo_solicitante' => $origin], ['imobiliariaVinculada' => $company]);
    $builder = app(PortoQuotePayloadBuilder::class);

    expect($builder->partnerCode($quote->lead))->toBe($expected);
    expect($builder->build($quote)['imobiliaria'] ?? null)->toBe($expected);
})->with([
    'cadastrada com código' => ['imobiliaria_cadastrada', ['id' => 9, 'name' => 'Imobiliária', 'porto_partner_code' => '000123'], '000123'],
    'cadastrada sem código' => ['imobiliaria_cadastrada', ['id' => 9, 'name' => 'Imobiliária', 'porto_partner_code' => null], null],
    'cadastrada com código malformado' => ['imobiliaria_cadastrada', ['id' => 9, 'name' => 'Imobiliária', 'porto_partner_code' => '123'], null],
    'código Tokio não é código Porto' => ['imobiliaria_cadastrada', ['id' => 9, 'name' => 'Imobiliária', 'tokio_partner_code' => '555555'], null],
    'outra origem com código' => ['locador', ['id' => 9, 'name' => 'Imobiliária', 'porto_partner_code' => '000123'], null],
]);

it('rejects data the Porto contract does not accept', function (array $attributes, array $relations, array $config, string $message) {
    config($config);

    expect(fn () => portoPayload('primary', $attributes, $relations))
        ->toThrow(InvalidInsuranceAnalysisPayload::class, $message);
})->with([
    'aluguel abaixo do mínimo' => [[], ['despesas' => ['valor_aluguel' => '199.99']], [], 'entre R$ 200,00'],
    'aluguel acima do máximo' => [[], ['despesas' => ['valor_aluguel' => '10000.01']], [], 'entre R$ 200,00'],
    'encargo acima do aluguel' => [[], ['despesas' => ['valor_aluguel' => '1500', 'valor_condominio' => '1500.01']], [], 'superior ao aluguel'],
    'encargo negativo' => [[], ['despesas' => ['valor_aluguel' => '1500', 'valor_iptu' => '-1']], [], 'Encargos'],
    'comercial sem motivo' => [['tipo_locacao' => 'comercial'], [], [], 'motivo da contratação'],
    'comercial com motivo de moradia' => [['tipo_locacao' => 'comercial', 'motivo_contratacao' => 'A'], [], [], 'motivo da contratação'],
    'finalidade ausente' => [['tipo_locacao' => null], [], [], 'finalidade'],
    'documento inválido' => [['cpf' => '11111111111'], [], [], 'CPF/CNPJ'],
    'nascimento ausente' => [['data_nascimento' => null], [], [], 'data de nascimento'],
    'e-mail inválido' => [['email' => 'sem-arroba'], [], [], 'E-mail'],
    'telefone incompleto' => [['tel' => '1198765'], [], [], 'Telefone'],
    'CEP incompleto' => [[], ['endereco' => ['cep' => '0100100']], [], 'CEP'],
    'nome curto' => [['nome' => 'Ana'], [], [], 'entre 5 e 60'],
    'SUSEP ausente' => [[], [], ['services.porto.susep' => null], 'PORTO_SUSEP'],
    'SUSEP sem sufixo F ou J' => [[], [], ['services.porto.susep' => 'ABC123'], 'PORTO_SUSEP'],
    'CPF do cotador inválido' => [[], [], ['services.porto.cpf_cotador' => '12345678900'], 'CPF_COTADOR'],
    'CNPJ como cotador' => [[], [], ['services.porto.cpf_cotador' => '11222333000181'], 'CPF_COTADOR'],
    'reajuste fora do domínio' => [[], [], ['services.porto.rent_adjustment_code' => 'IGP_M'], 'reajuste'],
]);

it('requires the round validity dates', function () {
    $quote = portoBuilderQuote()->forceFill(['lease_end_date' => null]);

    expect(fn () => app(PortoQuotePayloadBuilder::class)->build($quote))
        ->toThrow(InvalidInsuranceAnalysisPayload::class, 'Vigência');
});

it('requires a valid spouse for the joint scenario', function () {
    expect(fn () => portoPayload('joint', [], ['conjuge' => ['nome' => 'Cônjuge de Teste', 'cpf' => '09238037698', 'data_nascimento' => '1992-03-04']]))
        ->toThrow(InvalidInsuranceAnalysisPayload::class, 'cônjuge');
});

it('builds an independent minimal commission update for each offer', function () {
    $builder = app(PortoQuotePayloadBuilder::class);

    $payloads = array_map($builder->commissionUpdate(...), ['0008', '0001', '0005', '0007']);

    expect($payloads[0])->toBe(['ofertas' => [['seguro' => '0008', 'comissao' => ['codComissao' => 15]]]]);
    expect(array_map(fn (array $payload) => $payload['ofertas'][0]['seguro'], $payloads))->toBe(['0008', '0001', '0005', '0007']);
    foreach ($payloads as $payload) {
        expect(array_keys($payload))->toBe(['ofertas'])->and($payload['ofertas'])->toHaveCount(1);
    }
});

it('rejects invalid offer codes and commission settings in the commission update', function (string $offer, mixed $commission) {
    config(['services.porto.commission' => $commission]);

    expect(fn () => app(PortoQuotePayloadBuilder::class)->commissionUpdate($offer))
        ->toThrow(InvalidInsuranceAnalysisPayload::class);
})->with([
    'oferta vazia' => ['', 15],
    'oferta com três dígitos' => ['008', 15],
    'oferta com letras' => ['00A8', 15],
    'comissão em fração' => ['0008', 0.15],
    'comissão em texto' => ['0008', '15'],
    'comissão acima de 100' => ['0008', 101],
]);
