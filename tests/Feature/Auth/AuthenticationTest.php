<?php

use App\Models\Imobiliaria;
use App\Models\TwoFactorCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertRedirect(route('empresa.login'));
});

test('users can authenticate using the login screen', function (string $path) {
    Mail::fake();
    $company = Imobiliaria::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    $response = $this->withSession([
        '2fa_passed' => true,
        'url.intended' => route('cep.show', ['cep' => '01001000']),
    ])->post($path, [
        'email' => $company->email,
        'password' => 'senha1234',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('2fa'))
        ->assertSessionMissing('2fa_passed')
        ->assertSessionMissing('url.intended');
    expect(TwoFactorCode::query()->where('user_id', $user->id)->count())->toBe(1);
    $this->get(route('company.dashboard'))->assertRedirect(route('2fa'));
})->with(['/login', '/empresa/login/post']);

test('users can not authenticate with invalid password', function () {
    $company = Imobiliaria::factory()->create();
    User::factory()->create(['company_id' => $company->id]);

    $this->post('/login', [
        'email' => $company->email,
        'password' => 'wrong-password',
    ])->assertRedirect(route('empresa.login'))->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

it('returns login validation and credential errors to the login page instead of CEP', function (array $credentials) {
    $this->withSession(['_previous.url' => route('cep.show', ['cep' => '01001000'])])
        ->post(route('empresa.login.post'), $credentials)
        ->assertRedirect(route('empresa.login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
})->with([
    'invalid format' => [['email' => 'invalid', 'password' => 'senha1234']],
    'unknown credentials' => [['email' => 'unknown@example.test', 'password' => 'senha1234']],
]);

it('returns invalid or expired challenges to 2fa and keeps the dashboard locked', function (string $code, bool $expired) {
    $company = Imobiliaria::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);
    TwoFactorCode::query()->create([
        'user_id' => $user->id,
        'code' => Hash::make('123456'),
        'expires_at' => $expired ? now()->subMinute() : now()->addMinutes(10),
    ]);

    $this->actingAs($user)
        ->withSession(['_previous.url' => route('cep.show', ['cep' => '01001000'])])
        ->post(route('2fa.verify.post'), ['code' => $code])
        ->assertRedirect(route('2fa'))
        ->assertSessionHasErrors('code')
        ->assertSessionMissing('2fa_passed');

    $this->get(route('company.dashboard'))->assertRedirect(route('2fa'));
})->with([
    'invalid format' => ['abc', false],
    'wrong code' => ['654321', false],
    'expired code' => ['123456', true],
]);

it('requires authentication for the company dashboard and every two factor action', function () {
    $this->get(route('company.dashboard'))->assertRedirect(route('empresa.login'));
    $this->get(route('2fa'))->assertRedirect(route('empresa.login'));
    $this->post(route('2fa.verify.post'), ['code' => '123456'])->assertRedirect(route('empresa.login'));
    $this->post(route('2fa.resend'))->assertRedirect(route('empresa.login'));
});
