<?php

use App\Models\Imobiliaria;
use App\Models\TwoFactorCode;
use App\Models\User;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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

function companyTwoFactorRecipientAddresses(): array
{
    $transport = Mail::mailer()->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    return collect($transport->messages()->last()->getOriginalMessage()->getTo())
        ->map(fn ($address): string => $address->getAddress())
        ->values()
        ->all();
}

it('sends the initial and resent codes to the company canonical email', function () {
    ['company' => $company] = companyTwoFactorFixture();

    $this->post(route('empresa.login.post'), [
        'email' => strtoupper($company->email),
        'password' => 'company-password',
    ])->assertRedirect(route('2fa'));

    expect(companyTwoFactorRecipientAddresses())->toBe([$company->email]);

    $this->post(route('2fa.resend'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(companyTwoFactorRecipientAddresses())->toBe([$company->email])
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
