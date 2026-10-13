<?php

use App\Enums\RamoAtividade;
use App\Jobs\StartInsuranceAnalysesBatchJob;
use App\Models\Corretor;
use App\Models\Imobiliaria;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->withoutVite();
    config(['features.insurance_analysis.enabled' => false]);
    Queue::fake();
    Http::preventStrayRequests();
});

function businessActivityPayload(array $overrides = []): array
{
    return array_replace([
        'aceite_termos' => '1',
        'nome' => 'Pretendente comercial',
        'email' => 'business-activity@example.test',
        'tel' => '11988887777',
        'cpf' => '52998224725',
        'data_nascimento' => '1992-02-29',
        'tipo_locacao' => 'comercial',
        'descrever_atividade' => 'Atividade no imóvel comercial',
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

/** @return array{0: string, 1: array<string, mixed>} */
function businessActivitySubmission(string $profile, array $overrides = []): array
{
    $payload = businessActivityPayload($overrides);

    if ($profile === 'imobiliaria_cadastrada') {
        $company = Imobiliaria::factory()->create(['lead_access_code' => 'RAMO12']);
        test()->post(route('simulation.registered-company.verify'), [
            'lead_access_code' => $company->lead_access_code,
        ])->assertRedirect(route('simulation.registered-company.form'));
        $payload['registered_company_context'] = $company->id;

        return [route('simulation.registered-company.store'), $payload];
    }

    if ($profile === 'locatario') {
        return [route('simulation.tenant.store'), $payload];
    }

    return [route('simulation.unregistered-company.store'), array_replace($payload, [
        'responsavel_tipo' => $profile,
        'responsavel_nome' => 'Responsável pela solicitação',
        'responsavel_email' => 'requester@example.test',
        'responsavel_telefone' => '11999998888',
    ])];
}

it('stores each business activity through every public requester profile', function (string $profile, int $code): void {
    [$url, $payload] = businessActivitySubmission($profile, ['ramo_atividade' => "  $code  "]);

    $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect(route('simulation.success'));

    $lead = Lead::query()->sole();
    expect($lead->ramo_atividade)->toBe(RamoAtividade::from($code))
        ->and($lead->attributesToArray()['ramo_atividade'])->toBe($code)
        ->and($lead->lead_empresa)->toBeNull();
    $this->assertDatabaseHas('leads', ['id' => $lead->id, 'ramo_atividade' => $code]);
    Queue::assertNotPushed(StartInsuranceAnalysesBatchJob::class);
    Http::assertNothingSent();
})->with(['locatario', 'locador', 'imobiliaria_nao_cadastrada', 'imobiliaria_cadastrada'])
    ->with(['comercio' => 21205, 'industria' => 21204, 'servicos' => 21206]);

it('stores business activity for a commercial company applicant', function (): void {
    $this->postJson(route('simulation.tenant.store'), businessActivityPayload([
        'cpf' => '11222333000181',
        'cpf_responsavel' => '11144477735',
        'nome_responsavel' => 'Representante da empresa',
        'ramo_atividade' => 21206,
    ]))->assertRedirect(route('simulation.success'))->assertSessionHasNoErrors();

    $lead = Lead::query()->sole();
    expect($lead->ramo_atividade)->toBe(RamoAtividade::Servicos)
        ->and($lead->lead_empresa->cnpj)->toBe('11222333000181');
});

it('keeps existing commercial forms working without the future selector', function (string $profile): void {
    [$url, $payload] = businessActivitySubmission($profile);

    $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect(route('simulation.success'));

    expect(Lead::query()->sole()->ramo_atividade)->toBeNull();
})->with(['locatario', 'locador', 'imobiliaria_nao_cadastrada', 'imobiliaria_cadastrada']);

it('accepts an empty commercial business activity during the backend rollout', function (mixed $value): void {
    $this->postJson(route('simulation.tenant.store'), businessActivityPayload(['ramo_atividade' => $value]))
        ->assertSessionHasNoErrors()->assertRedirect(route('simulation.success'));

    expect(Lead::query()->sole()->ramo_atividade)->toBeNull();
})->with([null, '', '   ']);

it('rejects malformed or unknown commercial business activities without side effects', function (mixed $value): void {
    $this->postJson(route('simulation.tenant.store'), businessActivityPayload(['ramo_atividade' => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors('ramo_atividade')
        ->assertJsonPath('errors.ramo_atividade.0', 'Selecione um ramo de atividade válido: Comércio, Indústria ou Serviços.');

    $this->assertDatabaseCount('leads', 0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with([
    'unknown code' => 99999,
    'zero' => 0,
    'negative code' => -21205,
    'description instead of code' => 'Comércio',
    'letters mixed with code' => 'abc21205',
    'markup' => '<b>21205</b>',
    'decimal' => 21205.5,
    'scientific notation' => '2.1205e4',
    'boolean' => true,
    'array' => [[21205]],
]);

it('discards a business activity supplied for residential rent', function (mixed $value): void {
    $this->postJson(route('simulation.tenant.store'), businessActivityPayload([
        'tipo_locacao' => 'residencial',
        'ramo_atividade' => $value,
    ]))->assertSessionHasNoErrors()->assertRedirect(route('simulation.success'));

    expect(Lead::query()->sole()->ramo_atividade)->toBeNull();
})->with([21205, [['invalid']]]);

it('does not overwrite an existing business activity through a public duplicate submission', function (): void {
    $url = route('simulation.tenant.store');
    $this->post($url, businessActivityPayload(['ramo_atividade' => 21205]))->assertSessionHasNoErrors();
    $this->post($url, businessActivityPayload(['ramo_atividade' => 21204]))->assertSessionHasNoErrors();

    expect(Lead::query()->sole()->ramo_atividade)->toBe(RamoAtividade::Comercio);
});

it('persists and audits authorized changes while preserving omitted commercial activity', function (bool $linked): void {
    $admin = Corretor::query()->create([
        'name' => 'Administrador',
        'email' => 'business-admin@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'permissions' => [],
        'active' => true,
        'first_login_verified_at' => now(),
    ]);
    $url = $linked
        ? route('admin.simulations.registered-company.store', Imobiliaria::factory()->create())
        : route('admin.simulations.unlinked.store', ['tipo' => 'locatario']);

    $this->actingAs($admin, 'admin')->post($url, businessActivityPayload(['ramo_atividade' => 21205]))
        ->assertSessionHasNoErrors()->assertRedirect();
    $lead = Lead::query()->sole();
    expect($lead->ramo_atividade)->toBe(RamoAtividade::Comercio)
        ->and($lead->data_edited_at)->toBeNull();

    $this->post($url, businessActivityPayload())->assertSessionHasNoErrors();
    expect($lead->refresh()->ramo_atividade)->toBe(RamoAtividade::Comercio)
        ->and($lead->data_edited_at)->toBeNull();

    $this->post($url, businessActivityPayload(['ramo_atividade' => 21204]))->assertSessionHasNoErrors();
    expect($lead->refresh()->ramo_atividade)->toBe(RamoAtividade::Industria)
        ->and($lead->data_edited_at)->not->toBeNull()
        ->and($lead->updated_by_corretor_id)->toBe($admin->id);

    $this->post($url, businessActivityPayload([
        'tipo_locacao' => 'residencial',
        'ramo_atividade' => 21204,
    ]))->assertSessionHasNoErrors();
    expect($lead->refresh()->ramo_atividade)->toBeNull();
})->with(['linked company' => true, 'unlinked tenant' => false]);
