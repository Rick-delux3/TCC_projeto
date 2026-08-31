<?php

use App\Models\Imobiliaria;
use App\Models\User;
use App\Notifications\CompanyResetPasswordNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['app.url' => 'https://app.example.test/']);
});

it('does not use the request host in user password reset links', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->withHeader('X-Forwarded-Host', 'forwarded-attacker.example')
        ->post('http://attacker.example/forgot-password', ['email' => $user->email])
        ->assertSessionHas('status');

    Notification::assertSentTo(
        $user,
        ResetPassword::class,
        function (ResetPassword $notification) use ($user): bool {
            $actionUrl = $notification->toMail($user)->actionUrl;

            expect(parse_url($actionUrl, PHP_URL_SCHEME))->toBe('https')
                ->and(parse_url($actionUrl, PHP_URL_HOST))->toBe('app.example.test')
                ->and($actionUrl)->not->toContain('attacker.example')
                ->and($actionUrl)->not->toContain('forwarded-attacker.example');

            return true;
        }
    );
});

it('does not use the request host in company password reset links', function () {
    Notification::fake();
    $company = Imobiliaria::query()->create([
        'name' => 'Imobiliaria Host Seguro',
        'email' => 'host-seguro@example.test',
        'phone' => '11999999999',
        'cnpj' => null,
        'password' => Hash::make('old-password'),
        'city' => 'Sao Paulo',
        'state' => 'SP',
    ]);

    $this->withHeader('X-Forwarded-Host', 'forwarded-attacker.example')
        ->post('http://attacker.example/empresa/forgot-password', ['email' => $company->email])
        ->assertSessionHas('status');

    Notification::assertSentTo(
        $company,
        CompanyResetPasswordNotification::class,
        function (CompanyResetPasswordNotification $notification) use ($company): bool {
            $actionUrl = $notification->toMail($company)->actionUrl;

            expect(parse_url($actionUrl, PHP_URL_SCHEME))->toBe('https')
                ->and(parse_url($actionUrl, PHP_URL_HOST))->toBe('app.example.test')
                ->and($actionUrl)->not->toContain('attacker.example')
                ->and($actionUrl)->not->toContain('forwarded-attacker.example');

            return true;
        }
    );
});
