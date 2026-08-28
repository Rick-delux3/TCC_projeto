<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportException;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => '  '.strtoupper($user->email).'  ']);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response->assertStatus(200);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => '  '.strtoupper($user->email).'  ',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('password reset link transport failures do not return a server error', function () {
    $user = User::factory()->create();
    $repository = Mockery::mock(\Illuminate\Auth\Passwords\TokenRepositoryInterface::class);
    $repository->shouldReceive('delete')
        ->once()
        ->with(Mockery::on(fn (User $candidate): bool => $candidate->is($user)));

    $broker = Mockery::mock(\Illuminate\Auth\Passwords\PasswordBroker::class);
    $broker->shouldReceive('sendResetLink')
        ->once()
        ->andThrow(new TransportException('Resend transport unavailable.'));
    $broker->shouldReceive('getRepository')
        ->once()
        ->andReturn($repository);

    Password::shouldReceive('broker')
        ->once()
        ->with('users')
        ->andReturn($broker);

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasErrors('email');
});
