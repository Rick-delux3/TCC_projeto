<?php

use App\Models\AtividadeEconomicaCodigo;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use App\Support\CompanyConstitution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->withoutVite();
    config(['features.insurance_analysis.enabled' => false, 'services.leadlovers.enabled' => false]);
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'America/Sao_Paulo'));
    $this->activityId = AtividadeEconomicaCodigo::factory()->create()->atividade_economica_id;
});

function constitutionPayload(array $overrides = []): array
{
    return array_replace([
        'aceite_termos' => '1', 'nome' => 'Pretendente comercial', 'email' => 'constitution@example.test',
        'tel' => '11988887777', 'cpf' => '11.222.333/0001-81', 'data_nascimento' => '1992-02-29',
        'cpf_responsavel' => '11144477735', 'nome_responsavel' => 'Representante da empresa',
        'tipo_locacao' => 'comercial', 'empresa_constituida' => ' s ', 'estado_civil' => 'solteiro',
        'atividade_economica_id' => test()->activityId,
        'valor_aluguel' => '1500', 'cep' => '01001000', 'logradouro' => 'Praça da Sé',
        'numero' => '100', 'bairro' => 'Sé', 'cidade_imovel' => 'São Paulo', 'estado' => 'SP',
    ], $overrides);
}

function constitutionAdmin(): Corretor
{
    return Corretor::query()->create([
        'name' => 'Administrador', 'email' => 'constitution-admin@example.test', 'password' => 'password',
        'role' => Corretor::ROLE_CEO, 'permissions' => [], 'active' => true, 'first_login_verified_at' => now(),
    ]);
}

it('derives company constitution fields in every public simulation profile', function (string $profile): void {
    $payload = constitutionPayload([
        'tipo_pessoa' => 'F', 'tpPessoa' => 'F', 'dataFundacao' => '01/01/1900',
        'data_fundacao_calculada' => '1900-01-01',
    ]);
    $route = 'simulation.tenant.store';
    if ($profile === 'imobiliaria_cadastrada') {
        $company = Imobiliaria::factory()->create(['lead_access_code' => 'CONST1']);
        $this->post(route('simulation.registered-company.verify'), ['lead_access_code' => 'CONST1'])->assertRedirect();
        $payload['registered_company_context'] = $company->id;
        $route = 'simulation.registered-company.store';
    } elseif ($profile !== 'locatario') {
        $route = 'simulation.unregistered-company.store';
        $payload = array_replace($payload, [
            'responsavel_tipo' => $profile, 'responsavel_nome' => 'Responsável',
            'responsavel_email' => 'responsavel@example.test', 'responsavel_telefone' => '11988887777',
        ]);
    }
    $this->post(route($route), $payload)->assertSessionHasNoErrors()->assertRedirect(route('simulation.success'));
    $lead = Lead::query()->sole();
    expect($lead->tokioCompanyConstitutionParameters())->toBe([
        'tpPessoa' => 'J', 'empresaConstituida' => 'S', 'dataFundacao' => '14/10/2019',
    ])->and($lead->data_fundacao_calculada)->toBeInstanceOf(CarbonImmutable::class);
    $this->assertDatabaseHas('leads', [
        'id' => $lead->id, 'tipo_pessoa' => 'J', 'empresa_constituida' => 'S', 'data_fundacao_calculada' => '2019-10-14',
    ]);
    Http::assertNothingSent();
})->with(['locatario', 'locador', 'imobiliaria_nao_cadastrada', 'imobiliaria_cadastrada']);

it('rejects no and malformed answers without saving or dispatching jobs', function (mixed $answer): void {
    $response = $this->postJson(route('simulation.tenant.store'), constitutionPayload(['empresa_constituida' => $answer]));
    $response->assertUnprocessable()->assertJsonValidationErrors('empresa_constituida');
    if (is_string($answer) && strtoupper(trim($answer)) === 'N') {
        $response->assertJsonPath('errors.empresa_constituida.0', CompanyConstitution::REJECTION_MESSAGE);
    }
    expect(Lead::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['N', ' n ', 'sim', '', null, true, 1, [['S']]]);

it('requires a constitution answer for commercial company submissions', function (): void {
    $payload = constitutionPayload();
    unset($payload['empresa_constituida']);
    $this->post(route('simulation.tenant.store'), $payload)->assertSessionHasErrors('empresa_constituida');
    expect(Lead::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('excludes constitution answers outside commercial company requests', function (string $document, string $rentalType, string $personType): void {
    $this->post(route('simulation.tenant.store'), constitutionPayload([
        'cpf' => $document, 'tipo_locacao' => $rentalType, 'empresa_constituida' => ['N'],
    ]))->assertSessionHasNoErrors();
    expect(Lead::query()->sole()->tokioCompanyConstitutionParameters())->toBe([
        'tpPessoa' => $personType, 'empresaConstituida' => null, 'dataFundacao' => null,
    ]);
})->with([
    ['52998224725', 'comercial', 'F'],
    ['52998224725', 'residencial', 'F'],
    ['11222333000181', 'residencial', 'J'],
]);

it('calculates the reference date in Sao Paulo and handles leap years', function (string $now, string $expected): void {
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
    $this->post(route('simulation.tenant.store'), constitutionPayload())->assertSessionHasNoErrors();
    expect(Lead::query()->sole()->tokioCompanyConstitutionParameters()['dataFundacao'])->toBe($expected);
})->with([
    ['2026-10-14 01:30:00', '13/10/2019'],
    ['2028-02-29 15:00:00', '28/02/2021'],
]);

it('preserves existing public leads and blocks negative resubmissions', function (): void {
    $this->post(route('simulation.tenant.store'), constitutionPayload())->assertSessionHasNoErrors();
    $lead = Lead::query()->sole();
    $this->travel(1)->days();
    $this->post(route('simulation.tenant.store'), constitutionPayload())->assertSessionHasNoErrors();
    Queue::fake();
    $this->postJson(route('simulation.tenant.store'), constitutionPayload(['empresa_constituida' => 'N']))
        ->assertUnprocessable()->assertJsonPath('errors.empresa_constituida.0', CompanyConstitution::REJECTION_MESSAGE);
    expect($lead->fresh()->data_fundacao_calculada->toDateString())->toBe('2019-10-14');
    Queue::assertNothingPushed();
});

it('requires confirmation on admin simulation resubmission and resets it when the context changes', function (array $changes, string $personType): void {
    $this->actingAs(constitutionAdmin(), 'admin');
    $url = route('admin.simulations.unlinked.store', ['tipo' => 'locatario']);
    $this->post($url, constitutionPayload())->assertSessionHasNoErrors();
    $lead = Lead::query()->sole();
    $payload = constitutionPayload();
    unset($payload['empresa_constituida']);
    $this->travel(1)->days();
    $this->post($url, $payload)->assertSessionHasErrors('empresa_constituida');
    expect($lead->fresh()->data_fundacao_calculada->toDateString())->toBe('2019-10-14')
        ->and($lead->fresh()->data_edited_at)->toBeNull();
    $this->post($url, array_replace($payload, $changes))->assertSessionHasNoErrors();
    expect($lead->fresh()->tokioCompanyConstitutionParameters())->toBe([
        'tpPessoa' => $personType,
        'empresaConstituida' => $changes['empresa_constituida'] ?? null,
        'dataFundacao' => isset($changes['empresa_constituida']) ? '15/10/2019' : null,
    ])->and($lead->fresh()->data_edited_at)->not->toBeNull();
})->with([
    [['cpf' => '04252011000110', 'empresa_constituida' => 'S'], 'J'],
    [['cpf' => '52998224725'], 'F'],
    [['tipo_locacao' => 'residencial'], 'J'],
]);

it('clears stale constitution data when an admin edits the existing lead', function (array $changes, string $personType): void {
    $this->post(route('simulation.tenant.store'), constitutionPayload())->assertSessionHasNoErrors();
    $lead = Lead::query()->sole();
    $this->actingAs(constitutionAdmin(), 'admin');
    $this->post(route('admin.leads.update', $lead), $changes)->assertSessionHasNoErrors();
    expect($lead->fresh()->tokioCompanyConstitutionParameters())->toBe([
        'tpPessoa' => $personType, 'empresaConstituida' => null, 'dataFundacao' => null,
    ]);
})->with([
    [['cpf' => '04252011000110', 'cpf_responsavel' => '11144477735', 'nome_responsavel' => 'Representante'], 'J'],
    [['cpf' => '52998224725'], 'F'],
    [['tipo_locacao' => 'residencial'], 'J'],
]);
