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
        ->and($dom->query('//option[@value="separado"]')->length)->toBe(1)
        ->and($dom->query('//*[@data-simulation-fields]//input')->length)->toBe(0);
})->with([
    'simulation.tenant.form',
    'simulation.registered-company.form',
    'simulation.unregistered-company.form',
]);

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
