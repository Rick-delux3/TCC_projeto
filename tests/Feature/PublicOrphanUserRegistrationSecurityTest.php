<?php

use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

it('sends the generic registration entry point to the legitimate company registration', function () {
    $this->get('/register')
        ->assertRedirect(route('empresa.register.form'));
});

it('does not allow unauthenticated creation of an orphan user account', function () {
    Notification::fake();

    $this->post('/register', [
        'name' => 'Orphan Account',
        'email' => 'orphan@example.test',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
    ])->assertMethodNotAllowed();

    $this->assertGuest();
    $this->assertDatabaseMissing('users', [
        'email' => 'orphan@example.test',
    ]);
    Notification::assertNothingSent();

    expect(config('fortify.features'))->not->toContain(Features::registration());
});

it('does not allow orphan registration through alternate URL forms', function (string $url) {
    Notification::fake();

    $response = $this->post($url, [
        'name' => 'Alternate Orphan Account',
        'email' => 'alternate-orphan@example.test',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
    ]);

    expect($response->status())->toBeIn([404, 405]);
    $this->assertGuest();
    $this->assertDatabaseMissing('users', [
        'email' => 'alternate-orphan@example.test',
    ]);
    Notification::assertNothingSent();
})->with([
    'trailing slash' => '/register/',
    'query string' => '/register?source=external',
]);
