<?php

use App\Models\Imobiliaria;
use App\Models\User;
use App\Notifications\CompanyResetPasswordNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportException;

function companyForPasswordRecovery(array $overrides = []): Imobiliaria
{
    return Imobiliaria::query()->create(array_merge([
        'name' => 'Imobiliária Recuperação',
        'email' => 'recuperacao@example.test',
        'phone' => '11999999999',
        'cnpj' => null,
        'password' => Hash::make('old-password'),
        'city' => 'São Paulo',
        'state' => 'SP',
    ], $overrides));
}

it('renders the company password recovery form', function () {
    $this->get(route('company.password.request'))
        ->assertOk()
        ->assertSee('Enviar link de recuperacao');
});

it('styles company password pages only for tcc while preserving form destinations', function (string $profile) {
    config(['branding.active' => $profile]);
    $this->withoutVite();

    foreach ([
        [route('company.password.request'), route('company.password.email')],
        [route('company.password.reset', ['token' => 'preview-token', 'email' => 'company@example.test']), route('company.password.store')],
    ] as [$page, $destination]) {
        $response = $this->get($page)->assertOk();
        $response->assertSee('action="'.$destination.'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('href="'.route('empresa.login').'"', false)
            ->assertSee('imgs/segure-chave-a-mao-ao-ar-livre.jpg', false);

        if ($profile === 'tcc') {
            $response->assertSee('tcc-company-auth-body', false)
                ->assertSee('tcc-company-auth-main', false)
                ->assertSee('password-reset-card client-auth-card', false)
                ->assertSee('imgs/Logo_NVS.png', false);
        } else {
            $response->assertDontSee('tcc-company-auth', false)
                ->assertSee('class="password-reset-card"', false);
        }

        if (str_contains($page, 'preview-token')) {
            $response->assertSee('name="token" value="preview-token"', false)
                ->assertSee('value="company@example.test"', false)
                ->assertSee('name="password_confirmation"', false);
        }
    }
})->with(['tcc', 'client']);

it('sends a company reset link using the companies broker', function () {
    Notification::fake();
    $company = companyForPasswordRecovery();

    $this->post(route('company.password.email'), [
        'email' => strtoupper($company->email),
    ])->assertSessionHas('status');

    Notification::assertSentTo(
        $company,
        CompanyResetPasswordNotification::class,
        function (CompanyResetPasswordNotification $notification) use ($company): bool {
            $mail = $notification->toMail($company);

            return filled($notification->token)
                && $mail->subject === 'Redefinição de senha - '.config(
                    'branding.profiles.'.config('branding.active', 'tcc').'.name'
                )
                && $mail->view === 'emails.notifications.company-reset-password'
                && $mail->viewData['expiresInMinutes'] === 60;
        }
    );
});

it('handles mail transport failures without returning a server error', function () {
    $company = companyForPasswordRecovery([
        'email' => 'unavailable@example.test',
    ]);
    $repository = Mockery::mock(\Illuminate\Auth\Passwords\TokenRepositoryInterface::class);
    $repository->shouldReceive('delete')
        ->once()
        ->with(Mockery::on(fn (Imobiliaria $candidate) => $candidate->is($company)));

    $broker = Mockery::mock(\Illuminate\Auth\Passwords\PasswordBroker::class);
    $broker->shouldReceive('sendResetLink')
        ->once()
        ->andThrow(new TransportException('Transport unavailable.'));
    $broker->shouldReceive('getRepository')
        ->once()
        ->andReturn($repository);

    Password::shouldReceive('broker')
        ->once()
        ->with('companies')
        ->andReturn($broker);

    $this->from(route('company.password.request'))
        ->post(route('company.password.email'), [
            'email' => 'unavailable@example.test',
        ])
        ->assertRedirect(route('company.password.request'))
        ->assertSessionHasErrors('email');
});

it('resets a company password with a valid token', function () {
    Notification::fake();
    $company = companyForPasswordRecovery();
    $user = User::factory()->create([
        'company_id' => $company->id,
        'email' => $company->email,
        'password' => Hash::make('old-password'),
    ]);
    $token = null;

    $this->post(route('company.password.email'), [
        'email' => $company->email,
    ]);

    Notification::assertSentTo(
        $company,
        CompanyResetPasswordNotification::class,
        function (CompanyResetPasswordNotification $notification) use (&$token) {
            $token = $notification->token;

            return true;
        }
    );

    $this->post(route('company.password.store'), [
        'token' => $token,
        'email' => '  '.strtoupper($company->email).'  ',
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])->assertRedirect(route('empresa.login'));

    expect(Hash::check('new-secure-password', $company->fresh()->password))->toBeTrue()
        ->and(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
});

it('requires post and an authenticated session to log a company out', function () {
    $this->get(route('empresa.logout'))->assertMethodNotAllowed();
    $this->post(route('empresa.logout'))->assertRedirect(route('empresa.login'));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('empresa.logout'))
        ->assertRedirect(route('empresa.login'));

    $this->assertGuest();
});

it('rejects invalid reset tokens without changing the password', function () {
    $company = companyForPasswordRecovery();
    $previousPassword = $company->password;

    $this->post(route('company.password.store'), [
        'token' => 'invalid-token',
        'email' => $company->email,
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])->assertSessionHasErrors('email');

    expect($company->fresh()->password)->toBe($previousPassword);
});

it('isolates company password reset tokens from user reset tokens', function () {
    $sharedEmail = 'shared-reset-address@example.test';
    $company = companyForPasswordRecovery(['email' => $sharedEmail]);
    $user = User::factory()->create(['email' => $sharedEmail]);

    $companyToken = Password::broker('companies')->createToken($company);

    expect(config('auth.passwords.companies.table'))
        ->toBe('company_password_reset_tokens')
        ->not->toBe(config('auth.passwords.users.table'))
        ->and(Password::broker('companies')->tokenExists($company, $companyToken))->toBeTrue()
        ->and(Password::broker('users')->tokenExists($user, $companyToken))->toBeFalse();

    $userToken = Password::broker('users')->createToken($user);

    expect(Password::broker('users')->tokenExists($user, $userToken))->toBeTrue()
        ->and(Password::broker('companies')->tokenExists($company, $userToken))->toBeFalse();

    $this->assertDatabaseHas('company_password_reset_tokens', ['email' => $sharedEmail]);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $sharedEmail]);
});
