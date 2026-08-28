<?php

use App\Models\Imobiliaria;
use App\Models\TwoFactorCode;
use App\Models\User;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    config(['mail.default' => 'array']);
    Mail::forgetMailers();
});

function companyTwoFactorFixture(): array
{
    $company = Imobiliaria::query()->create([
        'name' => 'Imobiliária do 2FA',
        'email' => 'imobiliaria-2fa@example.test',
        'phone' => '11999999999',
        'cnpj' => null,
        'password' => Hash::make('company-password'),
        'city' => 'São Paulo',
        'state' => 'SP',
    ]);

    $user = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'usuario-administrador@example.test',
    ]);

    return compact('company', 'user');
}

function companyTwoFactorMessage(): Email
{
    $transport = Mail::mailer()->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    $message = $transport->messages()->last()->getOriginalMessage();

    expect($message)->toBeInstanceOf(Email::class);

    return $message;
}

function companyTwoFactorRecipientAddresses(): array
{
    return collect(companyTwoFactorMessage()->getTo())
        ->map(fn ($address): string => $address->getAddress())
        ->values()
        ->all();
}

it('sends the initial and resent codes to the company canonical email', function () {
    ['company' => $company] = companyTwoFactorFixture();
    $this->travelTo(now()->setTime(14, 30));

    $this->post(route('empresa.login.post'), [
        'email' => strtoupper($company->email),
        'password' => 'company-password',
    ])->assertRedirect(route('2fa'));

    expect(companyTwoFactorRecipientAddresses())->toBe([$company->email])
        ->and(companyTwoFactorMessage()->getSubject())->toBe('Seu código de verificação')
        ->and(companyTwoFactorMessage()->getHtmlBody())->toContain('Este código expira às 14:40 UTC.');

    $this->post(route('2fa.resend'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(companyTwoFactorRecipientAddresses())->toBe([$company->email])
        ->and(companyTwoFactorMessage()->getSubject())->toBe('Seu código de verificação')
        ->and(companyTwoFactorMessage()->getHtmlBody())->toContain('Este código expira às 14:40 UTC.')
        ->and(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(2);
});

it('fails safely when the authenticated user is not linked to a company', function () {
    $user = User::factory()->create(['company_id' => null]);

    $this->actingAs($user)
        ->from(route('2fa'))
        ->post(route('2fa.resend'))
        ->assertRedirect(route('2fa'))
        ->assertSessionHasErrors('code');

    expect(TwoFactorCode::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
});

it('cancels the initial challenge when email delivery fails', function () {
    ['company' => $company, 'user' => $user] = companyTwoFactorFixture();

    Mail::shouldReceive('send')
        ->once()
        ->andThrow(new TransportException('Resend transport unavailable.'));

    $this->from(route('empresa.login'))
        ->post(route('empresa.login.post'), [
            'email' => $company->email,
            'password' => 'company-password',
        ])
        ->assertRedirect(route('empresa.login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(TwoFactorCode::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('invalidates a resent challenge when email delivery fails', function () {
    ['user' => $user] = companyTwoFactorFixture();

    Mail::shouldReceive('send')
        ->once()
        ->andThrow(new TransportException('Resend transport unavailable.'));

    $this->actingAs($user)
        ->from(route('2fa'))
        ->post(route('2fa.resend'))
        ->assertRedirect(route('2fa'))
        ->assertSessionHasErrors('code');

    expect(TwoFactorCode::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
