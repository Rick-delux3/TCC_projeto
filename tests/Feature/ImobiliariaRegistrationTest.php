<?php

use App\Models\Imobiliaria;
use App\Models\LeadLoversTag;
use App\Models\TwoFactorCode;
use App\Models\User;
use App\Services\Access\CompanyTwoFactorMailService;
use App\Services\Lookup\CepService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;

beforeEach(function () {
    Http::preventStrayRequests();
    Illuminate\Support\Facades\Bus::fake();

    $this->mock(CepService::class, function (MockInterface $mock) {
        $mock->shouldReceive('find')
            ->with('01001000')
            ->andReturn([
                'cep' => '01001000',
                'cidade' => 'São Paulo',
                'estado' => 'SP',
            ]);
    });
});

function validImobiliariaRegistrationPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'imobiliaria@example.test',
        'phone' => '(11) 99999-9999',
        'cnpj' => '11.222.333/0001-81',
        'cep' => '01001-000',
        'password' => 'senha1234',
        'password_confirmation' => 'senha1234',
        'city' => 'São Paulo',
        'state' => 'sp',
    ], $overrides);
}

it('creates or reconciles the company tag and persists its association once', function (bool $alreadyRemote): void {
    config(['services.leadlovers.enabled' => true, 'services.leadlovers.token' => 'test-token', 'services.leadlovers.api_url' => 'https://leadlovers.example.test']);
    $company = Imobiliaria::factory()->create(['name' => 'Imobiliária Nova Parceira', 'leadlovers_tag_id' => null]);
    $remote = ['id' => 888, 'name' => $company->name];
    Http::fake(function ($request) use ($alreadyRemote, $remote) {
        return $request->method() === 'GET'
            ? Http::response($alreadyRemote ? [$remote + ['createdAt' => '2026-11-16T00:00:00Z']] : [])
            : Http::response($remote);
    });
    $job = new App\Jobs\CreateCompanyLeadLoversTagJob($company->id);
    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);
    expect($company->fresh()->leadlovers_tag_id)->toBe(888)
        ->and($company->fresh()->leadlovers_tag_name)->toBe($company->name)
        ->and(LeadLoversTag::query()->sole()->title)->toBe($company->name);
    Http::assertSentCount($alreadyRemote ? 1 : 2);
    if (! $alreadyRemote) {
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://leadlovers.example.test/tags/' && $request['name'] === $company->name);
    }
})->with([false, true]);

it('requires a unique typed name without dispatching a company tag for invalid data', function (string $name): void {
    LeadLoversTag::create(['leadlovers_tag_id' => 777, 'title' => 'Imobiliária Existente', 'active' => true]);
    $this->post(route('empresa.register.post'), validImobiliariaRegistrationPayload(['company_name' => $name]))
        ->assertSessionHasErrors('company_name');
    $this->assertDatabaseCount('imobiliarias', 0);
    Illuminate\Support\Facades\Bus::assertNotDispatched(App\Jobs\CreateCompanyLeadLoversTagJob::class);
    Http::assertNothingSent();
})->with(['', 'Existente']);

it('accepts a typed company name even when unused tags remain available', function () {
    Notification::fake();
    Illuminate\Support\Facades\Bus::fake();
    config(['services.leadlovers.enabled' => true]);
    LeadLoversTag::create([
        'leadlovers_tag_id' => 777, 'title' => 'Imobiliária Disponível',
        'key' => 'imobiliaria_disponivel', 'active' => true,
    ]);
    $this->get(route('empresa.register.form'))->assertOk()
        ->assertSee('name="company_name"', false)->assertDontSee('name="leadlovers_tag_id"', false);
    $this->post(route('empresa.register.post'), validImobiliariaRegistrationPayload(['company_name' => 'Nova Parceira']))
        ->assertRedirect(route('2fa'))->assertSessionHasNoErrors();
    $company = Imobiliaria::query()->sole();
    expect($company->name)->toBe('Imobiliária Nova Parceira')->and($company->leadlovers_tag_id)->toBeNull();
    Illuminate\Support\Facades\Bus::assertDispatched(App\Jobs\CreateCompanyLeadLoversTagJob::class,
        fn ($job) => $job->companyId === $company->id && $job->afterCommit === true && $job->queue === 'leadlovers');
    Notification::assertSentTo(User::query()->where('company_id', $company->id)->sole(), VerifyEmail::class);
    Http::assertNothingSent();
});

it('registers a typed company name locally without creating a remote tag', function (bool $enabled) {
    Notification::fake();

    config([
        'services.leadlovers.enabled' => $enabled,
        'services.leadlovers.token' => null,
    ]);

    $response = $this->post(
        route('empresa.register.post'),
        validImobiliariaRegistrationPayload([
            'company_name' => 'Auditada',
        ])
    );

    $response->assertRedirect(route('2fa'));
    $response->assertSessionHasNoErrors();

    $company = Imobiliaria::query()
        ->where('email', 'imobiliaria@example.test')
        ->firstOrFail();

    expect($company)
        ->name->toBe('Imobiliária Auditada')
        ->leadlovers_tag_id->toBeNull()
        ->leadlovers_tag_name->toBeNull();

    $user = User::query()->where('company_id', $company->id)->sole();

    expect($user->name)->toBe($company->name);
    Notification::assertSentTo($user, VerifyEmail::class);
    $this->assertDatabaseCount('lead_lovers_tags', 0);
    Http::assertNothingSent();
})->with([true, false]);

it('rejects tag selection on public registration', function () {
    $tag = LeadLoversTag::create([
        'leadlovers_tag_id' => 999,
        'title' => 'Imobiliária Disponível',
        'key' => 'imobiliaria_disponivel',
        'active' => true,
    ]);

    $this->from(route('empresa.register.form'))
        ->post(
            route('empresa.register.post'),
            validImobiliariaRegistrationPayload([
                'leadlovers_tag_id' => $tag->leadlovers_tag_id,
                'company_name' => 'Campo indevido',
            ])
        )
        ->assertRedirect(route('empresa.register.form'))
        ->assertSessionHasErrors('leadlovers_tag_id');
});

it('rejects registration when the honeypot field is filled', function () {
    $this->from(route('empresa.register.form'))
        ->post(
            route('empresa.register.post'),
            validImobiliariaRegistrationPayload([
                'company_name' => 'Robô',
                'website' => 'https://spam.example',
            ])
        )
        ->assertRedirect(route('empresa.register.form'))
        ->assertSessionHasErrors('website');
});

it('returns registration errors to the form even after a CEP lookup replaces the previous URL', function () {
    $this->get(route('empresa.register.form'))->assertOk();
    $this->get(route('cep.show', ['cep' => '01001000']), ['Accept' => 'application/json'])
        ->assertOk();

    $this->post(route('empresa.register.post'), validImobiliariaRegistrationPayload([
        'company_name' => 'Auditada',
        'password_confirmation' => 'different',
    ]))->assertRedirect(route('empresa.register.form'))
        ->assertSessionHasErrors('password');

    $this->assertGuest();
    $this->assertDatabaseCount('imobiliarias', 0);
});

it('requires the emailed registration challenge before entering the dashboard', function () {
    Notification::fake();
    $plainCode = null;
    $this->mock(CompanyTwoFactorMailService::class, function (MockInterface $mock) use (&$plainCode) {
        $mock->shouldReceive('sendCode')->once()->withArgs(function ($email, $code, $expiresAt) use (&$plainCode) {
            $plainCode = $code;

            return $email === 'imobiliaria@example.test' && $expiresAt->isFuture();
        });
    });

    $this->withSession(['2fa_passed' => true, 'url.intended' => route('cep.show', ['cep' => '01001000'])])
        ->post(route('empresa.register.post'), validImobiliariaRegistrationPayload(['company_name' => 'Auditada']))
        ->assertRedirect(route('2fa'))
        ->assertSessionMissing('2fa_passed')
        ->assertSessionMissing('url.intended');

    expect(Hash::check($plainCode, TwoFactorCode::query()->sole()->code))->toBeTrue();
    $this->get(route('2fa'))->assertOk()->assertViewIs('auth.2fa');
    $this->get(route('company.dashboard'))->assertRedirect(route('2fa'));

    $this->post(route('2fa.verify.post'), ['code' => $plainCode])
        ->assertRedirect(route('company.dashboard'))
        ->assertSessionHas('2fa_passed', true);

    $this->get(route('company.dashboard'))->assertOk();
    $this->get(route('2fa'))->assertRedirect(route('company.dashboard'));
    $this->assertDatabaseCount('two_factor_codes', 0);
});

it('preserves the registration but denies access when the challenge cannot be emailed', function () {
    Notification::fake();
    $this->mock(CompanyTwoFactorMailService::class, function (MockInterface $mock) {
        $mock->shouldReceive('sendCode')->once()->andThrow(new RuntimeException('Mail unavailable'));
    });

    $this->withSession(['2fa_passed' => true])
        ->post(route('empresa.register.post'), validImobiliariaRegistrationPayload(['company_name' => 'Auditada']))
        ->assertRedirect(route('empresa.login'))
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('2fa_passed');

    $this->assertGuest();
    $this->assertDatabaseCount('imobiliarias', 1);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('two_factor_codes', 0);
    $this->get(route('company.dashboard'))->assertRedirect(route('empresa.login'));
});

it('keeps the form as the previous page when CEP is requested by its JavaScript', function () {
    $this->get(route('empresa.register.form'))
        ->assertOk()
        ->assertSee("'X-Requested-With': 'XMLHttpRequest'", false);

    $this->get(route('cep.show', ['cep' => '01001000']), [
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ])->assertOk()->assertSessionHas('_previous.url', route('empresa.register.form'));
});
