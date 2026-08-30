<?php

use App\Models\Imobiliaria;
use App\Models\TwoFactorCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

function companyTwoFactorSecurityFixture(string $plainCode = '654321'): array
{
    $company = Imobiliaria::query()->create([
        'name' => 'Imobiliária Segurança 2FA',
        'email' => 'security-2fa-company@example.test',
        'phone' => '11999999999',
        'password' => Hash::make('company-password'),
        'city' => 'São Paulo',
        'state' => 'SP',
    ]);

    $user = User::factory()->create([
        'company_id' => $company->id,
        'email' => 'security-2fa-user@example.test',
    ]);

    $challenge = TwoFactorCode::query()->create([
        'user_id' => $user->id,
        'code' => Hash::make($plainCode),
        'expires_at' => now()->addMinutes(10),
    ]);

    return compact('company', 'user', 'challenge');
}

function clearCompanyTwoFactorSecurityRateLimits(User $user, array $ips): void
{
    foreach ($ips as $ip) {
        RateLimiter::clear("2fa:verify:{$user->id}:{$ip}");
    }
}

it('invalidates one company 2fa challenge after five failures across different IPs', function () {
    ['company' => $company, 'user' => $user, 'challenge' => $challenge]
        = companyTwoFactorSecurityFixture();
    $attackerIps = [
        '198.51.100.11',
        '198.51.100.12',
        '198.51.100.13',
        '198.51.100.14',
        '198.51.100.15',
    ];
    $correctAttemptIp = '203.0.113.90';

    clearCompanyTwoFactorSecurityRateLimits($user, [
        ...$attackerIps,
        $correctAttemptIp,
    ]);

    $this->actingAs($user)->withSession(['company_id' => $company->id]);

    foreach ($attackerIps as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from(route('2fa'))
            ->post(route('2fa.verify.post'), ['code' => '000000'])
            ->assertRedirect(route('2fa'))
            ->assertSessionHasErrors('code');
    }

    $this->withServerVariables(['REMOTE_ADDR' => $correctAttemptIp])
        ->from(route('2fa'))
        ->post(route('2fa.verify.post'), ['code' => '654321'])
        ->assertRedirect(route('2fa'))
        ->assertSessionHasErrors('code')
        ->assertSessionMissing('2fa_passed');

    expect(TwoFactorCode::query()->whereKey($challenge->id)->exists())->toBeFalse();
});

it('allows the valid company 2fa code before the challenge attempt limit', function () {
    ['company' => $company, 'user' => $user, 'challenge' => $challenge]
        = companyTwoFactorSecurityFixture();
    $attackerIps = [
        '198.51.100.21',
        '198.51.100.22',
        '198.51.100.23',
        '198.51.100.24',
    ];
    $correctAttemptIp = '203.0.113.91';

    clearCompanyTwoFactorSecurityRateLimits($user, [
        ...$attackerIps,
        $correctAttemptIp,
    ]);

    $this->actingAs($user)->withSession(['company_id' => $company->id]);

    foreach ($attackerIps as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->from(route('2fa'))
            ->post(route('2fa.verify.post'), ['code' => '000000'])
            ->assertRedirect(route('2fa'))
            ->assertSessionHasErrors('code');
    }

    expect($challenge->fresh()->attempts)->toBe(4);

    $this->withServerVariables(['REMOTE_ADDR' => $correctAttemptIp])
        ->post(route('2fa.verify.post'), ['code' => '654321'])
        ->assertRedirect(route('company.dashboard'))
        ->assertSessionHas('2fa_passed', true);

    expect(TwoFactorCode::query()->whereKey($challenge->id)->exists())->toBeFalse();
});

it('rejects a company 2fa challenge whose persisted attempt limit is already exhausted', function () {
    ['company' => $company, 'user' => $user, 'challenge' => $challenge]
        = companyTwoFactorSecurityFixture();
    $attemptIp = '203.0.113.92';

    $challenge->forceFill(['attempts' => 5])->save();
    clearCompanyTwoFactorSecurityRateLimits($user, [$attemptIp]);

    $this->actingAs($user)
        ->withSession(['company_id' => $company->id])
        ->withServerVariables(['REMOTE_ADDR' => $attemptIp])
        ->from(route('2fa'))
        ->post(route('2fa.verify.post'), ['code' => '654321'])
        ->assertRedirect(route('2fa'))
        ->assertSessionHasErrors('code')
        ->assertSessionMissing('2fa_passed');

    expect(TwoFactorCode::query()->whereKey($challenge->id)->exists())->toBeFalse();
});
