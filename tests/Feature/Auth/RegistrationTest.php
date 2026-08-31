<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

test('generic registration entry redirects to company registration', function () {
    $this->get('/register')
        ->assertRedirect(route('empresa.register.form'));
});

test('generic user registration is not available', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertMethodNotAllowed();

    $this->assertGuest();
    $this->assertDatabaseMissing('users', [
        'email' => 'test@example.com',
    ]);
});

test('generic registration cannot send verification email', function () {
    Notification::fake();

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertMethodNotAllowed();

    Notification::assertNothingSent();

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse()
        ->and(config('fortify.features'))->not->toContain(Features::registration());
});
