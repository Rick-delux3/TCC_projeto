<?php

use App\Models\User;
use Illuminate\Contracts\Notifications\Dispatcher;
use Symfony\Component\Mailer\Exception\TransportException;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    config(['features.insurance_analysis.enabled' => false]);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('company.dashboard', absolute: false));
});

test('registration completes safely when verification email delivery fails', function () {
    $this->mock(Dispatcher::class, function ($dispatcher): void {
        $dispatcher->shouldReceive('send')
            ->once()
            ->andThrow(new TransportException('Resend transport unavailable.'));
    });

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'test@example.com')->exists())->toBeTrue();
});
