<?php

use App\Models\Corretor;

beforeEach(function () {
    config([
        'admin.ceo_registration_enabled' => true,
        'admin.ceo_registration_secret' => 'test-secret',
    ]);
});

it('renders contextual icons and accessible password controls on the CEO registration form', function () {
    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.11'])
        ->post(route('admin.ceo.register.authorize'), [
            'key' => 'test-secret',
        ])
        ->assertRedirect(route('admin.ceo.register.form'));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.11'])
        ->get(route('admin.ceo.register.form'));

    $response->assertOk()
        ->assertSee('admin-input-icon', false)
        ->assertSee('data-toggle-password="password"', false)
        ->assertSee('data-toggle-password="password_confirmation"', false)
        ->assertSee('data-password-icon="show"', false)
        ->assertSee('data-password-icon="hide"', false)
        ->assertSee('name="website"', false)
        ->assertSee('minlength="8"', false)
        ->assertDontSee('>Ver</button>', false);

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('renders the CPF icon and accessible password control on the CEO login form', function () {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.12'])
        ->get(route('admin.ceo.login'));

    $response->assertOk()
        ->assertSee('admin-input-icon', false)
        ->assertSee('autocomplete="username"', false)
        ->assertSee('data-toggle-password="password"', false)
        ->assertSee('data-password-icon="show"', false)
        ->assertSee('data-password-icon="hide"', false)
        ->assertDontSee('>Ver</button>', false);
});

it('renders the email icon and accessible password control on the member login form', function () {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.13'])
        ->get(route('admin.login'));

    $response->assertOk()
        ->assertSee('client-input-icon', false)
        ->assertSee('autocomplete="username"', false)
        ->assertSee('data-toggle-password="password"', false)
        ->assertSee('data-password-icon="show"', false)
        ->assertSee('data-password-icon="hide"', false)
        ->assertDontSee('>Ver</button>', false);
});

it('rejects an invalid CPF, weak password, and a filled honeypot on CEO registration', function () {
    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.14'])
        ->post(route('admin.ceo.register.authorize'), [
            'key' => 'test-secret',
        ])
        ->assertRedirect(route('admin.ceo.register.form'));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.14'])
        ->from(route('admin.ceo.register.form'))
        ->post(route('admin.ceo.register.post'), [
            'website' => 'https://spam.example',
            'name' => 'CEO Teste',
            'email' => 'ceo@example.test',
            'cpf' => '11111111111',
            'password' => 'fraca',
            'password_confirmation' => 'fraca',
        ]);

    $response
        ->assertRedirect()
        ->assertSessionHasErrors(['website', 'cpf', 'password']);

    expect(Corretor::query()->where('role', Corretor::ROLE_CEO)->exists())
        ->toBeFalse();
});

it('registers one CEO with normalized and validated credentials', function () {
    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.15'])
        ->post(route('admin.ceo.register.authorize'), [
            'key' => 'test-secret',
        ])
        ->assertRedirect(route('admin.ceo.register.form'));

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.15'])
        ->post(route('admin.ceo.register.post'), [
            'website' => '',
            'name' => '  CEO   Teste  ',
            'email' => ' CEO@EXAMPLE.TEST ',
            'cpf' => '529.982.247-25',
            'password' => 'senha1234',
            'password_confirmation' => 'senha1234',
        ]);

    $response
        ->assertRedirect(route('admin.ceo.login'))
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('corretores', [
        'name' => 'CEO Teste',
        'email' => 'ceo@example.test',
        'cpf' => '52998224725',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
    ]);

    expect(Corretor::query()->where('role', Corretor::ROLE_CEO)->count())->toBe(1);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.15'])
        ->get(route('admin.ceo.register.access'))
        ->assertForbidden();
});
