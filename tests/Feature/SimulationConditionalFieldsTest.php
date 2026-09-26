<?php

use App\Enums\TipoLocacao;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Models\LeadEmpresa;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    config(['features.insurance_analysis.enabled' => false]);
    Queue::fake();
    Http::preventStrayRequests();
});

function conditionalSimulationPayload(array $overrides = []): array
{
    return array_merge([
        'aceite_termos' => '1',
        'nome' => 'Pretendente da simulação',
        'email' => 'conditional@example.test',
        'tel' => '11988887777',
        'cpf' => '529.982.247-25',
        'tipo_locacao' => 'residencial',
        'estado_civil' => 'solteiro',
        'valor_aluguel' => '1500',
        'cep' => '01001000',
        'logradouro' => 'Praça da Sé',
        'numero' => '100',
        'bairro' => 'Sé',
        'cidade_imovel' => 'São Paulo',
        'estado' => 'SP',
    ], $overrides);
}

function conditionalSimulationCompany(): Imobiliaria
{
    return Imobiliaria::query()->create([
        'name' => 'Imobiliária da simulação',
        'email' => 'conditional-company@example.test',
        'phone' => '11999999999',
        'password' => 'password',
        'city' => 'São Paulo',
        'state' => 'SP',
        'lead_access_code' => 'COND12',
        'lead_form_active' => true,
    ]);
}

function conditionalSimulationDom(string $html): DOMXPath
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($document);
}

function auditedSimulationPayload(string $route, array $overrides = []): array
{
    $payload = conditionalSimulationPayload();

    if ($route === 'simulation.registered-company.store') {
        $company = Imobiliaria::factory()->create(['lead_access_code' => 'AUD123']);
        test()->post(route('simulation.registered-company.verify'), ['lead_access_code' => $company->lead_access_code])
            ->assertRedirect(route('simulation.registered-company.form'));
        $payload['registered_company_context'] = $company->id;
    } elseif ($route === 'simulation.unregistered-company.store') {
        $payload = array_merge($payload, [
            'responsavel_tipo' => 'imobiliaria_nao_cadastrada',
            'responsavel_nome' => 'Responsável pelo imóvel',
            'responsavel_email' => 'responsavel@example.test',
            'responsavel_telefone' => '11999998888',
        ]);
    }

    return array_replace($payload, $overrides);
}

it('rejects malformed public simulation amounts without persisting or dispatching', function (string $route, string $field, mixed $value) {
    $this->postJson(route($route), auditedSimulationPayload($route, [$field => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('leads', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['simulation.tenant.store', 'simulation.unregistered-company.store', 'simulation.registered-company.store'])
    ->with([
        'negative rent' => ['valor_aluguel', '-1500'],
        'negative expense' => ['valor_agua', '-10'],
        'formatted negative expense' => ['valor_gas', 'R$ -10,50'],
        'optional amount containing letters' => ['valor_luz', 'inválido'],
        'letters mixed with digits' => ['valor_iptu', 'erro12'],
        'currency without amount' => ['outras_despesas', 'R$'],
        'array amount' => ['valor_condominio', ['10']],
    ]);

it('audits validation errors and absence of persistence in each public lead form', function (string $route, string $field, mixed $value) {
    $formRoute = match ($route) {
        'simulation.registered-company.store' => 'simulation.registered-company.form',
        'simulation.unregistered-company.store' => 'simulation.unregistered-company.form',
        default => 'simulation.tenant.form',
    };
    $payload = auditedSimulationPayload($route, [$field => $value]);

    $this->from(route($formRoute))->post(route($route), $payload)
        ->assertRedirect(route($formRoute))
        ->assertSessionHasErrors($field)
        ->assertSessionHasInput('tipo_locacao', 'residencial');

    $this->assertDatabaseCount('leads', 0);
    expect(LeadEmpresa::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    $this->get(route($formRoute))->assertOk()->assertSee('simulation-form-errors', false);
})->with(['simulation.tenant.store', 'simulation.unregistered-company.store', 'simulation.registered-company.store'])
    ->with([
        'missing name' => ['nome', null],
        'short name' => ['nome', 'ab'],
        'long name' => ['nome', str_repeat('a', 256)],
        'array name' => ['nome', ['invalid']],
        'numeric name' => ['nome', 123],
        'missing email' => ['email', null],
        'invalid email' => ['email', 'invalid'],
        'long email' => ['email', str_repeat('a', 256).'@example.test'],
        'array email' => ['email', ['invalid']],
        'missing phone' => ['tel', null],
        'short phone' => ['tel', '123456789'],
        'long phone' => ['tel', '123456789012'],
        'array phone' => ['tel', ['11988887777']],
        'missing consent' => ['aceite_termos', null],
        'rejected consent' => ['aceite_termos', '0'],
        'bot honeypot' => ['website', 'bot'],
        'missing rent' => ['valor_aluguel', null],
        'zero rent' => ['valor_aluguel', '0'],
        'excessive rent' => ['valor_aluguel', '1000000'],
        'excessive water' => ['valor_agua', '100000'],
        'excessive electricity' => ['valor_luz', '100000'],
        'excessive gas' => ['valor_gas', '100000'],
        'excessive property tax' => ['valor_iptu', '100000'],
        'excessive condo' => ['valor_condominio', '100000'],
        'excessive other expenses' => ['outras_despesas', '1000000'],
        'missing postcode' => ['cep', null],
        'short postcode' => ['cep', '1234567'],
        'long postcode' => ['cep', '123456789'],
        'array postcode' => ['cep', ['01001000']],
        'missing street' => ['logradouro', null],
        'long street' => ['logradouro', str_repeat('a', 256)],
        'array street' => ['logradouro', ['invalid']],
        'long number' => ['numero', str_repeat('a', 21)],
        'array number' => ['numero', ['100']],
        'long complement' => ['complemento', str_repeat('a', 101)],
        'array complement' => ['complemento', ['invalid']],
        'missing district' => ['bairro', null],
        'long district' => ['bairro', str_repeat('a', 101)],
        'array district' => ['bairro', ['invalid']],
        'missing city' => ['cidade_imovel', null],
        'long city' => ['cidade_imovel', str_repeat('a', 101)],
        'array city' => ['cidade_imovel', ['invalid']],
        'missing state' => ['estado', null],
        'long state' => ['estado', 'SPA'],
        'array state' => ['estado', ['SP']],
        'invalid marital status' => ['estado_civil', 'invalid'],
        'long notes' => ['observacoes', str_repeat('a', 2001)],
        'array notes' => ['observacoes', ['invalid']],
        'short filling responsible name' => ['responsavel_preenchimento', 'ab'],
        'long filling responsible name' => ['responsavel_preenchimento', str_repeat('a', 256)],
        'array filling responsible name' => ['responsavel_preenchimento', ['invalid']],
        'short filling responsible phone' => ['telefone_responsavel', '123456789'],
        'long filling responsible phone' => ['telefone_responsavel', '123456789012'],
        'short informed company name' => ['nome_imobiliaria_informada', 'ab'],
        'long informed company name' => ['nome_imobiliaria_informada', str_repeat('a', 256)],
        'invalid informed company document length' => ['cnpj_imobiliaria_informada', '1234567890123'],
        'long landlord name' => ['nome_locador', str_repeat('a', 256)],
        'invalid landlord email' => ['email_locador', 'invalid'],
        'short landlord phone' => ['telefone_locador', '123456789'],
        'long landlord phone' => ['telefone_locador', '123456789012'],
    ]);

it('audits the unregistered requester dependencies without writing a lead', function (string $field, mixed $value) {
    $route = 'simulation.unregistered-company.store';
    $this->postJson(route($route), auditedSimulationPayload($route, [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('leads', 0);
    Queue::assertNothingPushed();
})->with([
    'missing type' => ['responsavel_tipo', null],
    'invalid type' => ['responsavel_tipo', 'locatario'],
    'array type' => ['responsavel_tipo', ['locador']],
    'missing name' => ['responsavel_nome', null],
    'long name' => ['responsavel_nome', str_repeat('a', 256)],
    'array name' => ['responsavel_nome', ['invalid']],
    'missing email' => ['responsavel_email', null],
    'invalid email' => ['responsavel_email', 'invalid'],
    'long email' => ['responsavel_email', str_repeat('a', 256).'@example.test'],
    'array email' => ['responsavel_email', ['invalid']],
    'missing phone' => ['responsavel_telefone', null],
    'long phone' => ['responsavel_telefone', str_repeat('1', 21)],
    'array phone' => ['responsavel_telefone', ['11999998888']],
]);

it('audits valid public simulation boundaries and formatted values', function (string $route, array $overrides) {
    $this->post(route($route), auditedSimulationPayload($route, $overrides))
        ->assertRedirect(route('simulation.success'))->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();
    expect($lead->endereco)->not->toBeNull()
        ->and($lead->despesas)->not->toBeNull()
        ->and($lead->email)->toBe('conditional@example.test')
        ->and($lead->cpf)->toBe('52998224725');
    Http::assertNothingSent();
})->with(['simulation.tenant.store', 'simulation.unregistered-company.store', 'simulation.registered-company.store'])
    ->with([
        'minimum name phone and rent' => [['nome' => 'Ana', 'tel' => '1133334444', 'valor_aluguel' => '1']],
        'maximum text lengths' => [[
            'nome' => str_repeat('a', 255), 'logradouro' => str_repeat('b', 255),
            'bairro' => str_repeat('c', 100), 'cidade_imovel' => str_repeat('d', 100),
            'numero' => str_repeat('1', 20), 'complemento' => str_repeat('e', 100),
            'observacoes' => str_repeat('f', 2000),
        ]],
        'maximum monetary limits' => [[
            'valor_aluguel' => '999999.99', 'valor_agua' => '99999.999', 'valor_luz' => '99999.999',
            'valor_gas' => '99999.999', 'valor_iptu' => '99999.999', 'valor_condominio' => '99999.999',
            'outras_despesas' => '999999.99',
        ]],
        'explicit zero expenses' => [[
            'valor_agua' => '0', 'valor_luz' => '0', 'valor_gas' => '0',
            'valor_iptu' => '0', 'valor_condominio' => '0', 'outras_despesas' => '0',
        ]],
        'formatted Brazilian amounts' => [['valor_aluguel' => 'R$ 1.234,56', 'valor_agua' => '12,50']],
        'normalized strings' => [['nome' => '  Ana   Silva ', 'email' => ' CONDITIONAL@EXAMPLE.TEST ', 'tel' => '(11) 98888-7777', 'estado' => ' sp ']],
        'nullable optional fields' => [['estado_civil' => null, 'numero' => null, 'complemento' => null, 'observacoes' => null]],
    ]);

it('audits numeric defaults and normalized persistence without real integrations', function () {
    $this->post(route('simulation.tenant.store'), conditionalSimulationPayload([
        'valor_aluguel' => 'R$ 1.234,50', 'valor_agua' => '0', 'valor_luz' => null,
        'valor_gas' => '12,50', 'estado' => 'sp', 'email' => ' CONDITIONAL@EXAMPLE.TEST ',
    ]))->assertRedirect(route('simulation.success'))->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();
    expect((float) $lead->despesas->valor_aluguel)->toBe(1234.5)
        ->and((float) $lead->despesas->valor_agua)->toBe(0.0)
        ->and((float) $lead->despesas->valor_luz)->toBe(123.45)
        ->and((float) $lead->despesas->valor_gas)->toBe(12.5)
        ->and((float) $lead->despesas->valor_total_encargos)->toBe(1370.45)
        ->and($lead->endereco->estado)->toBe('SP')
        ->and($lead->email)->toBe('conditional@example.test');
    Http::assertNothingSent();
});

it('audits the public profile selection redirects', function (string $profile, string $nextRoute, array $parameters) {
    $this->post(route('simulation.profile'), ['tipo_solicitante' => $profile])
        ->assertRedirect(route($nextRoute, $parameters))->assertSessionHasNoErrors();
    $this->assertDatabaseCount('leads', 0);
    Queue::assertNothingPushed();
})->with([
    'registered company' => ['imobiliaria_cadastrada', 'simulation.registered-company.access', []],
    'unregistered company' => ['imobiliaria_nao_cadastrada', 'simulation.unregistered-company.form', ['responsavel_tipo' => 'imobiliaria_nao_cadastrada']],
    'tenant' => ['locatario', 'simulation.tenant.form', []],
    'landlord' => ['locador', 'simulation.unregistered-company.form', ['responsavel_tipo' => 'locador']],
]);

it('audits invalid public profile selection as JSON', function (mixed $value) {
    $this->postJson(route('simulation.profile'), ['tipo_solicitante' => $value])
        ->assertUnprocessable()->assertJsonValidationErrors('tipo_solicitante');
    $this->assertDatabaseCount('leads', 0);
    Queue::assertNothingPushed();
})->with([null, '', 'admin', [['locatario']], 123]);

it('audits invalid access code inputs and safely redisplays the access form', function (mixed $code) {
    $form = route('simulation.registered-company.access');
    $this->from($form)->post(route('simulation.registered-company.verify'), ['lead_access_code' => $code])
        ->assertRedirect($form)->assertSessionHasErrors('lead_access_code');
    $this->get($form)->assertOk()->assertSee('id="code-error"', false);
    $this->assertDatabaseCount('leads', 0);
    Queue::assertNothingPushed();
})->with([null, '', 'UNKNOWN', str_repeat('a', 21), [['AUD123']], 123]);

it('audits inline recovery errors for malformed email values', function (mixed $email) {
    $form = route('simulation.registered-company.code.request');
    $this->from($form)->post(route('simulation.registered-company.code.email'), ['email' => $email])
        ->assertRedirect($form)->assertSessionHasErrors('email');
    $this->get($form)->assertOk()->assertSee('id="email-error"', false);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([null, '', 'invalid', [['invalid@example.test']], 123]);

it('audits invalid requester query parameters as a missing public page', function (mixed $type) {
    $this->get(route('simulation.unregistered-company.form', ['responsavel_tipo' => $type]))->assertNotFound();
})->with(['locatario', 'admin', [['locador']]]);

it('audits the shared required field messages for public JSON submissions', function () {
    $this->postJson(route('simulation.tenant.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'aceite_termos', 'nome', 'email', 'tel', 'cpf', 'tipo_locacao',
            'valor_aluguel', 'cep', 'logradouro', 'bairro', 'cidade_imovel', 'estado',
        ])
        ->assertJsonPath('errors.nome.0', 'Informe o nome completo.')
        ->assertJsonPath('errors.cpf.0', 'Informe o CPF ou CNPJ.')
        ->assertJsonPath('errors.aceite_termos.0', 'Você precisa aceitar os termos para continuar.');
    $this->assertDatabaseCount('leads', 0);
    Queue::assertNothingPushed();
});

it('audits public submission throttling without sending data externally', function () {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson(route('simulation.profile'), [])->assertUnprocessable();
    }

    $this->postJson(route('simulation.profile'), [])->assertTooManyRequests();
    $this->assertDatabaseCount('leads', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('audits the simulation postcode endpoint without calling providers for invalid input', function (string $postcode, bool $matchesRoute) {
    $response = $this->getJson('/cep/'.$postcode);

    if ($matchesRoute) {
        $response->assertUnprocessable()->assertJsonPath('success', false);
    } else {
        $response->assertNotFound();
    }

    Http::assertNothingSent();
})->with([
    'short postcode' => ['1234567', true],
    'long postcode' => ['123456789', true],
    'letters' => ['abcdefgh', false],
]);

it('audits postcode autocomplete and cached responses with fake providers', function () {
    Http::fake([
        'https://viacep.com.br/ws/01001000/json/' => Http::response([
            'cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'bairro' => 'Sé',
            'localidade' => 'São Paulo', 'uf' => 'SP',
        ]),
    ]);

    foreach (['01001000', '01001-000'] as $postcode) {
        $this->getJson('/cep/'.$postcode)->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cep', '01001000')
            ->assertJsonPath('data.logradouro', 'Praça da Sé')
            ->assertJsonPath('data.cidade', 'São Paulo')
            ->assertJsonPath('data.estado', 'SP');
    }

    Http::assertSentCount(1);
});

it('audits postcode provider fallback and not found responses', function (bool $found) {
    Http::fake([
        'https://viacep.com.br/ws/01001000/json/' => Http::response(['erro' => true]),
        'https://brasilapi.com.br/api/cep/v2/01001000' => $found
            ? Http::response(['cep' => '01001000', 'street' => 'Praça da Sé', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP'])
            : Http::response([], 404),
    ]);

    $response = $this->getJson('/cep/01001000');

    if ($found) {
        $response->assertOk()->assertJsonPath('data.source', 'brasilapi');
    } else {
        $response->assertNotFound()->assertJsonPath('success', false);
    }
})->with([true, false]);

it('requires the shared CPF or CNPJ field before saving a public simulation', function (string $route, array $document) {
    $payload = conditionalSimulationPayload();
    unset($payload['cpf']);
    $payload = array_merge($payload, $document);

    if ($route === 'simulation.registered-company.store') {
        $company = conditionalSimulationCompany();
        $this->post(route('simulation.registered-company.verify'), ['lead_access_code' => $company->lead_access_code])->assertRedirect();
        $payload['registered_company_context'] = $company->id;
    } elseif ($route === 'simulation.unregistered-company.store') {
        $payload = array_merge($payload, [
            'responsavel_tipo' => 'locador', 'responsavel_nome' => 'Responsável teste',
            'responsavel_email' => 'requester@example.test', 'responsavel_telefone' => '11999998888',
        ]);
    }

    $this->post(route($route), $payload)->assertSessionHasErrors(['cpf' => 'Informe o CPF ou CNPJ.']);
    expect(Lead::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['simulation.tenant.store', 'simulation.registered-company.store', 'simulation.unregistered-company.store'])
    ->with([
        'missing' => [[]],
        'null' => [['cpf' => null]],
        'empty' => [['cpf' => '']],
        'whitespace' => [['cpf' => '   ']],
        'CPF mask only' => [['cpf' => '...-']],
        'CNPJ mask only' => [['cpf' => '../-']],
    ]);

it('persists the independent document and rental choices through every public profile', function (
    string $profile,
    string $document,
    string $rentalType
) {
    $payload = conditionalSimulationPayload([
        'cpf' => $document,
        'tipo_locacao' => $rentalType,
        'descrever_atividade' => '  Comércio   de roupas  ',
        'cpf_responsavel' => '111.444.777-35',
        'nome_responsavel' => '  Representante   da empresa  ',
    ]);
    $route = 'simulation.tenant.store';

    if ($profile === 'imobiliaria_cadastrada') {
        $company = conditionalSimulationCompany();
        $this->post(route('simulation.registered-company.verify'), [
            'lead_access_code' => $company->lead_access_code,
        ])->assertRedirect();
        $payload['registered_company_context'] = $company->id;
        $route = 'simulation.registered-company.store';
    } elseif ($profile !== 'locatario') {
        $route = 'simulation.unregistered-company.store';
        $payload = array_merge($payload, [
            'responsavel_tipo' => $profile,
            'responsavel_nome' => 'Responsável pela solicitação',
            'responsavel_email' => 'requester@example.test',
            'responsavel_telefone' => '11999998888',
        ]);
    }

    $this->post(route($route), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('simulation.success'));

    $lead = Lead::query()->sole();
    $isCompany = strlen(preg_replace('/\D/', '', $document)) === 14;

    expect($lead->tipo_solicitante)->toBe($profile)
        ->and($lead->tipo_locacao)->toBe(TipoLocacao::from($rentalType))
        ->and($lead->descrever_atividade)->toBe($rentalType === 'comercial' ? 'Comércio de roupas' : null)
        ->and($lead->cpf)->toBe($isCompany ? null : '52998224725')
        ->and($lead->lead_empresa()->count())->toBe($isCompany ? 1 : 0);

    if ($isCompany) {
        expect($lead->lead_empresa->cnpj)->toBe('11222333000181')
            ->and($lead->lead_empresa->cpf_responsavel)->toBe('11144477735')
            ->and($lead->lead_empresa->nome_responsavel)->toBe('Representante da empresa')
            ->and($lead->lead_empresa->lead->is($lead))->toBeTrue();
    }
})->with(['locatario', 'locador', 'imobiliaria_nao_cadastrada', 'imobiliaria_cadastrada'])
    ->with(['529.982.247-25', '11.222.333/0001-81'])
    ->with(['residencial', 'comercial']);

it('discards inapplicable fields even when a public submission supplies invalid hidden values', function (?string $status) {
    $this->post(route('simulation.tenant.store'), conditionalSimulationPayload([
        'estado_civil' => $status,
        'descrever_atividade' => ['invalid'],
        'cpf_responsavel' => ['invalid'],
        'nome_responsavel' => ['invalid'],
        'conjuge_nome' => ['invalid'],
        'conjuge_cpf' => 'not-a-cpf',
    ]))->assertSessionHasNoErrors()->assertRedirect(route('simulation.success'));

    $lead = Lead::query()->sole();
    expect($lead->descrever_atividade)->toBeNull()
        ->and($lead->lead_empresa)->toBeNull()
        ->and($lead->conjuge)->toBeNull();
})->with(['solteiro', 'separado', null]);

it('rejects invalid or missing conditional data without creating a lead', function (array $overrides, array $errors) {
    $this->post(route('simulation.tenant.store'), conditionalSimulationPayload($overrides))
        ->assertSessionHasErrors($errors);

    expect(Lead::query()->count())->toBe(0)
        ->and(LeadEmpresa::query()->count())->toBe(0);
})->with([
    'rental type required' => [['tipo_locacao' => null], ['tipo_locacao']],
    'invalid rental type' => [['tipo_locacao' => 'industrial'], ['tipo_locacao']],
    'array rental type' => [['tipo_locacao' => ['comercial']], ['tipo_locacao']],
    'commercial activity required' => [['tipo_locacao' => 'comercial'], ['descrever_atividade']],
    'commercial activity too long' => [['tipo_locacao' => 'comercial', 'descrever_atividade' => str_repeat('a', 56)], ['descrever_atividade']],
    'commercial activity must be text' => [['tipo_locacao' => 'comercial', 'descrever_atividade' => ['invalid']], ['descrever_atividade']],
    'invalid CPF checksum' => [['cpf' => '52998224724'], ['cpf']],
    'invalid CNPJ checksum' => [['cpf' => '11222333000180'], ['cpf']],
    'repeated CPF digits' => [['cpf' => '11111111111'], ['cpf']],
    'document must be text' => [['cpf' => ['52998224725']], ['cpf']],
    'letters must not be stripped from document' => [['cpf' => 'x52998224725'], ['cpf']],
    'company representative required' => [['cpf' => '11222333000181'], ['cpf_responsavel', 'nome_responsavel']],
    'representative CPF checksum' => [['cpf' => '11222333000181', 'cpf_responsavel' => '11144477734', 'nome_responsavel' => 'Representante'], ['cpf_responsavel']],
    'representative must have CPF not CNPJ' => [['cpf' => '11222333000181', 'cpf_responsavel' => '11222333000181', 'nome_responsavel' => 'Representante'], ['cpf_responsavel']],
    'representative name length' => [['cpf' => '11222333000181', 'cpf_responsavel' => '11144477735', 'nome_responsavel' => str_repeat('a', 56)], ['nome_responsavel']],
    'representative name type' => [['cpf' => '11222333000181', 'cpf_responsavel' => '11144477735', 'nome_responsavel' => ['invalid']], ['nome_responsavel']],
    'married spouse required' => [['estado_civil' => 'casado'], ['conjuge_nome', 'conjuge_cpf']],
    'stable union spouse required' => [['estado_civil' => 'uniao_estavel'], ['conjuge_nome', 'conjuge_cpf']],
    'spouse CPF differs from applicant' => [['estado_civil' => 'casado', 'conjuge_nome' => 'Cônjuge', 'conjuge_cpf' => '52998224725'], ['conjuge_cpf']],
]);

it('preserves the existing spouse rules for the other marital statuses', function (string $status) {
    $this->post(route('simulation.tenant.store'), conditionalSimulationPayload([
        'estado_civil' => $status,
        'conjuge_nome' => 'Cônjuge Teste',
        'conjuge_cpf' => '111.444.777-35',
    ]))->assertSessionHasNoErrors();

    expect(Lead::query()->sole()->conjuge->cpf)->toBe('11144477735');
})->with(['casado', 'uniao_estavel', 'divorciado', 'viuvo']);

it('preserves leading zeroes in company and representative documents', function () {
    $this->post(route('simulation.tenant.store'), conditionalSimulationPayload([
        'cpf' => '04.252.011/0001-10',
        'cpf_responsavel' => '012.345.678-90',
        'nome_responsavel' => str_repeat('a', 55),
        'tipo_locacao' => 'comercial',
        'descrever_atividade' => str_repeat('b', 55),
    ]))->assertSessionHasNoErrors();

    expect(LeadEmpresa::query()->sole()->cnpj)->toBe('04252011000110')
        ->and(LeadEmpresa::query()->sole()->cpf_responsavel)->toBe('01234567890');
});

it('updates and removes conditional relationships only through an authorized admin submission', function () {
    $admin = Corretor::query()->create([
        'name' => 'Administrador',
        'email' => 'conditional-admin@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
    $url = route('admin.simulations.unlinked.store', ['tipo' => 'locatario']);

    $this->actingAs($admin, 'admin')->post($url, conditionalSimulationPayload())->assertSessionHasNoErrors();

    $companyPayload = conditionalSimulationPayload([
        'cpf' => '11222333000181',
        'cpf_responsavel' => '11144477735',
        'nome_responsavel' => 'Representante',
        'tipo_locacao' => 'comercial',
        'descrever_atividade' => 'Loja',
        'estado_civil' => 'casado',
        'conjuge_nome' => 'Cônjuge',
        'conjuge_cpf' => '52998224725',
    ]);

    $this->post($url, $companyPayload)->assertSessionHasNoErrors();
    $this->post($url, $companyPayload)->assertSessionHasNoErrors();
    $lead = Lead::query()->sole();
    expect($lead->lead_empresa()->count())->toBe(1)
        ->and($lead->cpf)->toBeNull()
        ->and($lead->descrever_atividade)->toBe('Loja')
        ->and($lead->conjuge)->not->toBeNull();

    $this->post(route('simulation.tenant.store'), conditionalSimulationPayload())
        ->assertSessionHasNoErrors();
    expect($lead->fresh()->lead_empresa)->not->toBeNull();

    $this->post($url, conditionalSimulationPayload(['estado_civil' => 'separado']))->assertSessionHasNoErrors();
    $lead->refresh();
    expect($lead->cpf)->toBe('52998224725')
        ->and($lead->descrever_atividade)->toBeNull()
        ->and($lead->lead_empresa)->toBeNull()
        ->and($lead->conjuge)->toBeNull();
});

it('enforces one company per lead in the database', function () {
    $lead = Lead::query()->create(['nome' => 'Legado', 'email' => 'legacy@example.test']);
    expect($lead->fresh()->tipo_locacao)->toBeNull();

    $lead->lead_empresa()->create(['cnpj' => '11222333000181']);
    expect(fn () => $lead->lead_empresa()->create(['cnpj' => '04252011000110']))
        ->toThrow(QueryException::class);
});

it('deletes the company with its lead', function () {
    $lead = Lead::query()->create(['nome' => 'Legado', 'email' => 'legacy@example.test']);
    $lead->lead_empresa()->create(['cnpj' => '11222333000181']);
    $lead->delete();

    expect(LeadEmpresa::query()->count())->toBe(0);
});

it('rolls back the lead when company persistence fails', function () {
    $this->withoutExceptionHandling();
    Event::listen('eloquent.creating: '.LeadEmpresa::class, function (): void {
        throw new RuntimeException('Company save failed');
    });

    expect(fn () => $this->post(route('simulation.tenant.store'), conditionalSimulationPayload([
        'cpf' => '11222333000181',
        'cpf_responsavel' => '11144477735',
        'nome_responsavel' => 'Representante',
    ])))->toThrow(RuntimeException::class, 'Company save failed');

    expect(Lead::query()->count())->toBe(0);
});

it('renders the same conditional controls in all simulation views', function (string $route) {
    $company = conditionalSimulationCompany();
    $this->post(route('simulation.registered-company.verify'), [
        'lead_access_code' => $company->lead_access_code,
    ])->assertRedirect();
    $html = $this->get(route($route))->assertOk()->getContent();
    $dom = conditionalSimulationDom($html);

    expect($dom->query('//input[@name="tipo_locacao"]')->length)->toBe(2)
        ->and($dom->query('//input[@name="cpf"]')->length)->toBe(1)
        ->and($dom->query('//input[@name="cpf" and @required]')->length)->toBe(1)
        ->and($dom->query('//option[@value="separado"]')->length)->toBe(1)
        ->and($dom->query('//*[@data-simulation-fields]//input')->length)->toBe(0);
})->with([
    'simulation.tenant.form',
    'simulation.registered-company.form',
    'simulation.unregistered-company.form',
]);

it('shows spouse fields only for married or stable union in every simulation view', function (
    string $view,
    bool $isAdminSimulation,
    ?string $status,
    bool $showSpouse
) {
    $this->withSession(['_old_input' => conditionalSimulationPayload([
        'estado_civil' => $status,
        'conjuge_nome' => 'Cônjuge Teste',
        'conjuge_cpf' => '11144477735',
    ])]);
    $this->app['request']->setLaravelSession($this->app['session.store']);

    $html = (string) $this->view('simulation.forms.'.$view, [
        'company' => conditionalSimulationCompany(),
        'isAdminSimulation' => $isAdminSimulation,
        'errors' => new \Illuminate\Support\ViewErrorBag,
    ]);
    $dom = conditionalSimulationDom($html);
    $spouseFields = '//*[@data-simulation-fields="spouse"]//input';

    expect($dom->query($spouseFields)->length)->toBe($showSpouse ? 2 : 0)
        ->and($dom->query($spouseFields.'[@required]')->length)->toBe($showSpouse ? 2 : 0);

    if ($showSpouse) {
        expect($dom->evaluate('string('.$spouseFields.'[@name="conjuge_nome"]/@value)'))->toBe('Cônjuge Teste');
    }
})->with(['tenant', 'registered-company', 'unregistered-company_landlord'])
    ->with(['public' => false, 'internal' => true])
    ->with([
        'unselected' => [null, false],
        'single' => ['solteiro', false],
        'separated' => ['separado', false],
        'married' => ['casado', true],
        'stable union' => ['uniao_estavel', true],
        'divorced' => ['divorciado', false],
        'widowed' => ['viuvo', false],
    ]);

it('updates editable water and electricity defaults with the rent in simulation forms', function () {
    $html = $this->get(route('simulation.tenant.form'))->assertOk()->getContent();
    $dom = conditionalSimulationDom($html);
    $script = '';

    foreach ($dom->query('//script') as $element) {
        if (str_contains($element->textContent, 'function updateAutomaticExpenses()')) {
            $script = $element->textContent;
            break;
        }
    }

    expect($script)->not->toBeEmpty();

    foreach (['valor_agua', 'valor_luz'] as $field) {
        expect($dom->query('//input[@name="'.$field.'"][@readonly or @disabled]')->length)->toBe(0);
    }

    $process = new \Symfony\Component\Process\Process(['node', '-e', <<<'JS'
        const assert = require('node:assert/strict');
        const vm = require('node:vm');
        const script = require('node:fs').readFileSync(0, 'utf8');
        function setup(values = {}) {
            const inputs = Object.fromEntries(['valor_aluguel', 'valor_agua', 'valor_luz'].map(name => {
                const input = Object.assign(new EventTarget(), { value: values[name] ?? '', disabled: false, focus() {} });
                return [name, input];
            }));
            const selector = { value: '', selectedOptions: [{}], querySelectorAll: () => [] };
            const add = new EventTarget();
            const remove = ['valor_agua', 'valor_luz'].map(name => Object.assign(new EventTarget(), { dataset: { removeExpense: name } }));
            const wrappers = Object.fromEntries(['valor_agua', 'valor_luz'].map(name => {
                const classes = new Set();
                return [name, {
                    querySelector: () => inputs[name],
                    classList: { add: value => classes.add(value), remove: value => classes.delete(value), contains: value => classes.has(value) },
                }];
            }));
            const document = {
                getElementById: name => inputs[name] ?? ({ expenseSelector: selector, addExpenseButton: add }[name] ?? null),
                querySelector: query => wrappers[query.match(/data-expense-field="([^"]+)"/)[1]],
                querySelectorAll: query => query === '.expense-field' ? Object.values(wrappers) : remove,
                addEventListener: (event, callback) => callback(),
            };
            vm.runInNewContext(script, { document, Intl });
            return {
                inputs,
                change(name, value) { inputs[name].value = value; inputs[name].dispatchEvent(new Event('input')); },
                removeWater() { remove[0].dispatchEvent(new Event('click')); },
                addWater() { selector.value = 'valor_agua'; add.dispatchEvent(new Event('click')); },
            };
        }
        const form = setup();
        for (const rent of ['1300', '1.300,00', '1300.00']) {
            form.change('valor_aluguel', rent);
            assert.equal(form.inputs.valor_agua.value, '130,00');
            assert.equal(form.inputs.valor_luz.value, '130,00');
        }
        form.change('valor_agua', '95,50');
        form.change('valor_aluguel', '2000');
        assert.equal(form.inputs.valor_agua.value, '95,50');
        assert.equal(form.inputs.valor_luz.value, '200,00');
        form.change('valor_luz', '0');
        form.change('valor_aluguel', '3000');
        assert.equal(form.inputs.valor_luz.value, '0');
        form.removeWater();
        form.change('valor_aluguel', '4000');
        assert.equal(form.inputs.valor_agua.value, '');
        assert.equal(form.inputs.valor_agua.disabled, true);
        form.addWater();
        assert.equal(form.inputs.valor_agua.value, '400,00');
        assert.equal(form.inputs.valor_agua.disabled, false);
        form.change('valor_aluguel', '');
        assert.equal(form.inputs.valor_agua.value, '');
        assert.equal(form.inputs.valor_luz.value, '0');
        const restored = setup({ valor_aluguel: '1300', valor_agua: '80', valor_luz: '0' });
        restored.change('valor_aluguel', '1500');
        assert.equal(restored.inputs.valor_agua.value, '80');
        assert.equal(restored.inputs.valor_luz.value, '0');
        const initial = setup({ valor_aluguel: '1300.55' });
        assert.equal(initial.inputs.valor_agua.value, '130,06');
        assert.equal(initial.inputs.valor_luz.value, '130,06');
        console.log('utility defaults verified');
        JS]);
    $process->setInput($script);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('utility defaults verified');
});

it('restores active fields and choices after a validation error', function () {
    $this->withSession(['_old_input' => conditionalSimulationPayload([
        'cpf' => '11222333000181',
        'cpf_responsavel' => '11144477735',
        'nome_responsavel' => 'Representante',
        'tipo_locacao' => 'comercial',
        'descrever_atividade' => 'Loja',
        'estado_civil' => 'casado',
        'conjuge_nome' => 'Cônjuge',
        'conjuge_cpf' => '52998224725',
    ])]);
    $html = $this->get(route('simulation.tenant.form'))->assertOk()->getContent();
    $dom = conditionalSimulationDom($html);

    expect($dom->query('//*[@data-simulation-fields="company"]//input')->length)->toBe(2)
        ->and($dom->evaluate('string(//*[@data-simulation-fields="company"]//input[@name="nome_responsavel"]/@value)'))->toBe('Representante')
        ->and($dom->evaluate('string(//*[@data-simulation-fields="commercial"]//input/@value)'))->toBe('Loja')
        ->and($dom->query('//*[@data-simulation-fields="spouse"]//input')->length)->toBe(2)
        ->and($dom->evaluate('string(//input[@name="tipo_locacao"][@checked]/@value)'))->toBe('comercial');
});

it('renders the form after rejecting malformed conditional fields', function () {
    $url = route('simulation.tenant.form');
    $this->from($url)->post(route('simulation.tenant.store'), conditionalSimulationPayload([
        'cpf' => '11222333000181',
        'cpf_responsavel' => ['invalid'],
        'nome_responsavel' => ['invalid'],
        'tipo_locacao' => 'comercial',
        'descrever_atividade' => ['invalid'],
        'estado_civil' => 'casado',
        'conjuge_nome' => ['invalid'],
        'conjuge_cpf' => ['invalid'],
    ]))->assertRedirect($url)->assertSessionHasErrors([
        'cpf_responsavel', 'nome_responsavel', 'descrever_atividade', 'conjuge_nome', 'conjuge_cpf',
    ]);

    $this->get($url)->assertOk();
});

it('groups the profile fields into two stages within the original submission form', function (string $profile) {
    if ($profile === 'registered-company') {
        $company = conditionalSimulationCompany();
        $this->post(route('simulation.registered-company.verify'), [
            'lead_access_code' => $company->lead_access_code,
        ])->assertRedirect();
    }

    $dom = conditionalSimulationDom($this->get(route('simulation.'.$profile.'.form'))->assertOk()->getContent());
    $firstStep = '//form[@data-simulation-form]//section[@data-form-step="1"]';
    $secondStep = '//form[@data-simulation-form]//section[@data-form-step="2"]';

    expect($dom->query('//form[@data-simulation-form]')->length)->toBe(1)
        ->and($dom->evaluate('string(//form[@data-simulation-form]/@action)'))->toBe(route('simulation.'.$profile.'.store'))
        ->and($dom->query($firstStep.'//input[@name="nome"]')->length)->toBe(1)
        ->and($dom->query($secondStep.'//input[@name="valor_aluguel"][@required]')->length)->toBe(1)
        ->and($dom->query($secondStep.'//select[@name="estado"]/option')->length)->toBe(28)
        ->and($dom->query($secondStep.'//input[@name="aceite_termos"][@required]')->length)->toBe(1)
        ->and($dom->query($firstStep.'//input[@name="responsavel_nome"]')->length)->toBe($profile === 'unregistered-company' ? 1 : 0)
        ->and($dom->query($firstStep.'//input[@name="responsavel_preenchimento"]')->length)->toBe($profile === 'registered-company' ? 1 : 0)
        ->and($dom->query('//form[@data-simulation-form]//input[@name="_token"]')->length)->toBe(1);
})->with(['tenant', 'unregistered-company', 'registered-company']);

it('preserves optional requester fields in the internal form', function () {
    $html = (string) $this->view('simulation.forms.unregistered-company_landlord', [
        'isAdminSimulation' => true,
        'lockResponsavelTipo' => true,
        'responsavelTipo' => 'locador',
        'formAction' => route('admin.simulations.unlinked.store', ['tipo' => 'locador']),
        'errors' => new \Illuminate\Support\ViewErrorBag,
    ]);
    $dom = conditionalSimulationDom($html);

    foreach (['responsavel_nome', 'responsavel_email', 'responsavel_telefone'] as $field) {
        expect($dom->query('//input[@name="'.$field.'"]')->length)->toBe(1)
            ->and($dom->query('//input[@name="'.$field.'"][@required]')->length)->toBe(0);
    }

    expect($dom->evaluate('string(//input[@name="responsavel_tipo"]/@value)'))->toBe('locador');
});

it('opens the stage containing validation errors and restores property values and consent', function (array $errors, string $step) {
    $this->withSession([
        '_old_input' => conditionalSimulationPayload(['valor_gas' => '0', 'aceite_termos' => '1']),
        'errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag($errors)),
    ]);
    $html = $this->get(route('simulation.tenant.form'))->assertOk()->getContent();
    $dom = conditionalSimulationDom($html);

    expect($dom->evaluate('string(//*[@data-simulation-wizard]/@data-initial-step)'))->toBe($step)
        ->and($dom->query('//*[@role="alert"]//*[@data-error-field]')->length)->toBe(count($errors))
        ->and($dom->query('//*[@id="modalErrors"]')->length)->toBe(0)
        ->and($dom->evaluate('string(//input[@name="valor_gas"]/@value)'))->toBe('0')
        ->and($dom->query('//*[@data-expense-field="valor_gas"][contains(@class,"d-none")]')->length)->toBe(0)
        ->and($dom->evaluate('string(//select[@name="estado"]/option[@selected]/@value)'))->toBe('SP')
        ->and($dom->query('//input[@name="aceite_termos"][@checked]')->length)->toBe(1);
})->with([
    'people error' => [['nome' => 'Informe seu nome.'], '1'],
    'property error' => [['cep' => 'Informe um CEP válido.'], '2'],
    'commercial error' => [['descrever_atividade' => 'Descreva a atividade.'], '2'],
    'both stages' => [['cpf_responsavel' => 'Informe o CPF.', 'cep' => 'Informe o CEP.'], '1'],
]);
