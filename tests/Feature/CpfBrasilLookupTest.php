<?php

use App\Models\Lead;
use App\Services\Lookup\CpfLookupService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->withoutVite();
    config(['features.cpf_lookup.enabled' => true, 'features.insurance_analysis.enabled' => false, 'services.cpf_lookup.cpf_brasil.api_key' => 'test-token']);
    Cache::flush();
    Queue::fake();
    Http::preventStrayRequests();
});

function cpfBrasilPayload(array $overrides = []): array
{
    return array_merge(['aceite_termos' => '1', 'nome' => 'Pessoa de teste', 'email' => 'cpf@example.test', 'tel' => '11988887777', 'cpf' => '52998224725', 'tipo_locacao' => 'residencial', 'estado_civil' => 'solteiro', 'valor_aluguel' => '1500', 'cep' => '01001000', 'logradouro' => 'Praça da Sé', 'numero' => '100', 'bairro' => 'Sé', 'cidade_imovel' => 'São Paulo', 'estado' => 'SP'], $overrides);
}

it('resolves birthdates separately and caches only encrypted dates', function () {
    Http::fake(['api.cpf-brasil.org/*' => Http::response(['success' => true, 'data' => ['CPF' => '52998224725', 'NASC' => '29/02/1992']])]);
    $service = app(CpfLookupService::class);
    expect($service->lookupBirthdate('52998224725'))->toBe('1992-02-29')
        ->and($service->lookupBirthdate('52998224725'))->toBe('1992-02-29');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('X-API-Key', 'test-token') && $request->method() === 'GET');
    $key = 'cpf-brasil:'.hash_hmac('sha256', '52998224725', config('app.key'));
    expect(Cache::get($key))->not->toContain('1992-02-29');
});

it('stores automatic dates and ignores client supplied dates on public forms', function (string $route, array $extra) {
    Http::fake(['api.cpf-brasil.org/*' => Http::response(['success' => true, 'data' => ['CPF' => '52998224725', 'NASC' => '29/02/1992']])]);
    $this->post(route($route), cpfBrasilPayload(array_merge($extra, ['data_nascimento' => '2001-01-01'])))
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(Lead::query()->sole()->data_nascimento->toDateString())->toBe('1992-02-29');
})->with([
    ['simulation.tenant.store', []],
    ['simulation.unregistered-company.store', ['responsavel_tipo' => 'locador', 'responsavel_nome' => 'Proprietário teste', 'responsavel_email' => 'owner@example.test', 'responsavel_telefone' => '11988887777']],
]);

it('stores the spouse own birthdate', function () {
    Http::fake(fn ($request) => Http::response(['success' => true, 'data' => ['CPF' => basename($request->url()), 'NASC' => str_ends_with($request->url(), '52998224725') ? '29/02/1992' : '01/01/1990']]));
    $this->post(route('simulation.tenant.store'), cpfBrasilPayload(['estado_civil' => 'casado', 'conjuge_nome' => 'Conjuge teste', 'conjuge_cpf' => '07234828702']))->assertSessionHasNoErrors();
    expect(Lead::query()->sole()->conjuge->data_nascimento->toDateString())->toBe('1990-01-01');
    Http::assertSentCount(2);
});

it('does not query when disabled and accepts a synthetic CPF with manual birthdate', function () {
    config(['features.cpf_lookup.enabled' => false]);
    $this->post(route('simulation.tenant.store'), cpfBrasilPayload(['cpf' => '12345678901', 'data_nascimento' => '1992-02-29']))->assertSessionHasNoErrors();
    expect(Lead::query()->sole()->data_nascimento->toDateString())->toBe('1992-02-29');
    Http::assertNothingSent();
});

it('rejects provider errors without persisting a lead', function (string $code) {
    Http::fake(['api.cpf-brasil.org/*' => Http::response(['error' => 'Erro', 'code' => $code], 400)]);
    $this->post(route('simulation.tenant.store'), cpfBrasilPayload())->assertSessionHasErrors('cpf');
    expect(Lead::query()->count())->toBe(0);
    Http::assertSentCount(1);
})->with(['MISSING_API_KEY', 'INVALID_API_KEY', 'TOKEN_EXPIRED', 'PLAN_EXPIRED', 'PLAN_SUSPENDED', 'QUOTA_EXCEEDED', 'MISSING_CPF_PARAMETER', 'INVALID_CPF_FORMAT', 'CPF_NOT_FOUND', 'DATABASE_NOT_FOUND', 'DATABASE_QUERY_ERROR', 'DATABASE_ERROR']);

it('rejects invalid births and mismatched identities', function (string $cpf, string $date) {
    Http::fake(['api.cpf-brasil.org/*' => Http::response(['success' => true, 'data' => ['CPF' => $cpf, 'NASC' => $date]])]);
    expect(fn () => app(CpfLookupService::class)->lookupBirthdate('52998224725'))->toThrow(ValidationException::class);
})->with([['52998224725', '31/02/1992'], ['52998224725', '30/11/1899'], ['52998224725', '01/01/2999'], ['07234828702', '01/01/1990']]);

it('queries the registered company public form', function () {
    $company = \App\Models\Imobiliaria::factory()->create(['lead_access_code' => 'CPF123']);
    $this->post(route('simulation.registered-company.verify'), ['lead_access_code' => $company->lead_access_code])->assertRedirect();
    Http::fake(['api.cpf-brasil.org/*' => Http::response(['success' => true, 'data' => ['CPF' => '52998224725', 'NASC' => '29/02/1992']])]);
    $this->post(route('simulation.registered-company.store'), cpfBrasilPayload(['registered_company_context' => $company->id]))->assertSessionHasNoErrors();
    expect(Lead::query()->sole()->data_nascimento->toDateString())->toBe('1992-02-29');
    Http::assertSentCount(1);
});

it('queries the company representative instead of the CNPJ', function () {
    Http::fake(['api.cpf-brasil.org/*' => Http::response(['success' => true, 'data' => ['CPF' => '52998224725', 'NASC' => '29/02/1992']])]);
    $this->post(route('simulation.tenant.store'), cpfBrasilPayload(['cpf' => '11222333000181', 'cpf_responsavel' => '52998224725', 'nome_responsavel' => 'Responsável teste']))->assertSessionHasNoErrors();
    Http::assertSent(fn ($request) => $request->url() === 'https://api.cpf-brasil.org/cpf/52998224725');
});

it('queries birthdates in both administrative submission paths', function (bool $registered) {
    $admin = \App\Models\Corretor::query()->create(['name' => 'Admin teste', 'email' => 'admin-cpf@example.test', 'password' => 'password', 'role' => \App\Models\Corretor::ROLE_CEO, 'permissions' => [], 'active' => true, 'first_login_verified_at' => now()]);
    $this->actingAs($admin, 'admin');
    Http::fake(['api.cpf-brasil.org/*' => Http::response(['success' => true, 'data' => ['CPF' => '52998224725', 'NASC' => '29/02/1992']])]);
    $route = $registered
        ? route('admin.simulations.registered-company.store', \App\Models\Imobiliaria::factory()->create())
        : route('admin.simulations.unlinked.store', ['tipo' => 'locatario']);
    $this->post($route, cpfBrasilPayload())->assertSessionHasNoErrors();
    expect(Lead::query()->sole()->data_nascimento->toDateString())->toBe('1992-02-29');
})->with([true, false]);

it('blocks invalid CPF before making a paid lookup', function () {
    $this->post(route('simulation.tenant.store'), cpfBrasilPayload(['cpf' => '12345678901']))->assertSessionHasErrors('cpf');
    Http::assertNothingSent();
});

it('handles transport failures without leaking exception details', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('secret-provider-detail'));
    $this->post(route('simulation.tenant.store'), cpfBrasilPayload())->assertSessionHasErrors('cpf');
    expect(Lead::query()->count())->toBe(0);
});
