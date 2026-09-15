<?php

use App\Models\Imobiliaria;
use App\Models\LeadLoversTag;
use App\Models\User;
use App\Services\CepService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;

beforeEach(function () {
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

it('registers an imobiliaria using an available local tag', function () {
    Notification::fake();

    $tag = LeadLoversTag::create([
        'leadlovers_tag_id' => 777,
        'title' => 'Imobiliária Auditada',
        'key' => 'imobiliaria_auditada',
        'active' => true,
    ]);

    $response = $this->post(
        route('empresa.register.post'),
        validImobiliariaRegistrationPayload([
            'leadlovers_tag_id' => $tag->leadlovers_tag_id,
        ])
    );

    $response->assertRedirect(route('empresa.login'));
    $response->assertSessionHasNoErrors();

    $company = Imobiliaria::query()
        ->where('email', 'imobiliaria@example.test')
        ->firstOrFail();

    expect($company)
        ->name->toBe('Imobiliária Auditada')
        ->cep->toBe('01001000')
        ->leadlovers_tag_id->toBe(777);

    $user = User::query()
        ->where('company_id', $company->id)
        ->firstOrFail();

    Notification::assertSentTo($user, VerifyEmail::class);

    Http::assertNothingSent();
});

it('creates and stores a remote tag when no local tag is available', function () {
    Notification::fake();

    config([
        'services.leadlovers.enabled' => true,
        'services.leadlovers.token' => 'test-token',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://api.leadlovers.com/tags/' => Http::response([
            'id' => 888,
            'name' => 'Imobiliária Auditada',
        ], 200),
    ]);

    $response = $this->post(
        route('empresa.register.post'),
        validImobiliariaRegistrationPayload([
            'company_name' => 'Auditada',
        ])
    );

    $response->assertRedirect(route('empresa.login'));
    $response->assertSessionHasNoErrors();

    $company = Imobiliaria::query()
        ->where('email', 'imobiliaria@example.test')
        ->firstOrFail();

    expect($company)
        ->name->toBe('Imobiliária Auditada')
        ->leadlovers_tag_id->toBe(888);

    $this->assertDatabaseHas('lead_lovers_tags', [
        'leadlovers_tag_id' => 888,
        'title' => 'Imobiliária Auditada',
        'active' => true,
    ]);

    Http::assertSentCount(1);
});

it('rejects fields from the inactive registration mode', function () {
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
        ->assertSessionHasErrors('company_name');
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
