<?php

use App\Models\User;
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
