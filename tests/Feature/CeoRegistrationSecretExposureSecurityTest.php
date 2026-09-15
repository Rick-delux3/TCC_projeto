<?php

use App\Http\Middleware\EnsureCeoRegistrationIsAuthorized;
use App\Models\Corretor;

beforeEach(function () {
    config([
        'admin.ceo_registration_enabled' => true,
        'admin.ceo_registration_secret' => 'test-secret',
    ]);
});

it('does not authorize CEO creation with the bootstrap secret in the URL', function () {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.21'])
        ->post(route('admin.ceo.register.post', ['key' => 'test-secret']), [
            'website' => '',
            'name' => 'CEO Atacante',
            'email' => 'attacker@example.test',
            'cpf' => '52998224725',
            'password' => 'senha1234',
            'password_confirmation' => 'senha1234',
        ]);

    $response->assertForbidden();

    $this->assertDatabaseMissing('corretores', [
        'email' => 'attacker@example.test',
        'role' => Corretor::ROLE_CEO,
    ]);
});

it('accepts the bootstrap secret only from the authorization form body', function () {
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.22'])
        ->post(route('admin.ceo.register.authorize', ['key' => 'test-secret']));

    $response->assertForbidden();

    $this->assertDatabaseMissing('corretores', [
        'role' => Corretor::ROLE_CEO,
    ]);
});

it('does not expose a URL secret in the CEO access or registration form', function () {
    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.23'])
        ->get(route('admin.ceo.register.form', ['key' => 'test-secret']))
        ->assertRedirect(route('admin.ceo.register.access'));

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.23'])
        ->get(route('admin.ceo.register.access', ['key' => 'test-secret']))
        ->assertRedirect(route('admin.ceo.register.access'));

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.23'])
        ->get(route('admin.ceo.register.access'))
        ->assertOk()
        ->assertDontSee('value="test-secret"', false)
        ->assertDontSee('name="name"', false);
});

it('rejects an expired or future CEO registration session authorization', function (int $authorizedAt) {
    $response = $this
        ->withSession([
            EnsureCeoRegistrationIsAuthorized::SESSION_KEY => $authorizedAt,
        ])
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.24'])
        ->post(route('admin.ceo.register.post'), [
            'website' => '',
            'name' => 'CEO Atacante',
            'email' => 'attacker@example.test',
            'cpf' => '52998224725',
            'password' => 'senha1234',
            'password_confirmation' => 'senha1234',
        ]);

    $response->assertForbidden();

    $this->assertDatabaseMissing('corretores', [
        'email' => 'attacker@example.test',
        'role' => Corretor::ROLE_CEO,
    ]);
})->with([
    'expired authorization' => fn () => now()->subMinutes(16)->getTimestamp(),
    'future authorization' => fn () => now()->addMinute()->getTimestamp(),
]);
