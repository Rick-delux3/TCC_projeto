<?php

use App\Models\Corretor;
use App\Models\CorretorLoginVerificacaoCode;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

it('rejects an admin 2fa code when another attempt exhausts the challenge after it is read', function () {
    $corretor = Corretor::query()->create([
        'name' => 'Administrador 2FA Concorrente',
        'email' => 'admin-2fa-race@example.test',
        'password' => 'safe-password',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
        'first_login_verified_at' => null,
    ]);

    $plainCode = '654321';
    $challenge = CorretorLoginVerificacaoCode::query()->create([
        'corretor_id' => $corretor->id,
        'code_hash' => Hash::make($plainCode),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 4,
        'used_at' => null,
        'ip_address' => '198.51.100.40',
        'user_agent' => 'Pest security test',
    ]);

    $attemptIp = '198.51.100.41';
    $verifyKey = "admin:2fa:verify:{$corretor->id}:{$attemptIp}";
    $retrievedEvent = 'eloquent.retrieved: '.CorretorLoginVerificacaoCode::class;
    $concurrentFailureInjected = false;

    RateLimiter::clear($verifyKey);

    Event::listen(
        $retrievedEvent,
        function (CorretorLoginVerificacaoCode $retrievedChallenge) use (
            $challenge,
            &$concurrentFailureInjected
        ): void {
            if ($concurrentFailureInjected || ! $retrievedChallenge->is($challenge)) {
                return;
            }

            $concurrentFailureInjected = true;

            CorretorLoginVerificacaoCode::query()
                ->whereKey($challenge->id)
                ->update(['attempts' => 5]);
        },
    );

    try {
        $response = $this->actingAs($corretor, 'admin')
            ->withServerVariables(['REMOTE_ADDR' => $attemptIp])
            ->from(route('admin.2fa.form'))
            ->post(route('admin.2fa.verify'), ['code' => $plainCode]);
    } finally {
        Event::forget($retrievedEvent);
    }

    $response
        ->assertRedirect(route('admin.2fa.form'))
        ->assertSessionHasErrors('code')
        ->assertSessionMissing('admin_2fa_passed');

    expect($concurrentFailureInjected)->toBeTrue()
        ->and($corretor->fresh()->first_login_verified_at)->toBeNull()
        ->and($challenge->fresh()->attempts)->toBe(5)
        ->and($challenge->fresh()->used_at)->not->toBeNull();
});

it('does not overwrite failures recorded after an admin 2fa challenge is read', function () {
    $corretor = Corretor::query()->create([
        'name' => 'Administrador 2FA Contador',
        'email' => 'admin-2fa-counter-race@example.test',
        'password' => 'safe-password',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
        'first_login_verified_at' => null,
    ]);

    $challenge = CorretorLoginVerificacaoCode::query()->create([
        'corretor_id' => $corretor->id,
        'code_hash' => Hash::make('654321'),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
        'used_at' => null,
        'ip_address' => '198.51.100.50',
        'user_agent' => 'Pest security test',
    ]);

    $attemptIp = '198.51.100.51';
    $verifyKey = "admin:2fa:verify:{$corretor->id}:{$attemptIp}";
    $retrievedEvent = 'eloquent.retrieved: '.CorretorLoginVerificacaoCode::class;
    $concurrentFailuresInjected = false;

    RateLimiter::clear($verifyKey);

    Event::listen(
        $retrievedEvent,
        function (CorretorLoginVerificacaoCode $retrievedChallenge) use (
            $challenge,
            &$concurrentFailuresInjected
        ): void {
            if ($concurrentFailuresInjected || ! $retrievedChallenge->is($challenge)) {
                return;
            }

            $concurrentFailuresInjected = true;

            CorretorLoginVerificacaoCode::query()
                ->whereKey($challenge->id)
                ->update(['attempts' => 2]);
        },
    );

    try {
        $response = $this->actingAs($corretor, 'admin')
            ->withServerVariables(['REMOTE_ADDR' => $attemptIp])
            ->from(route('admin.2fa.form'))
            ->post(route('admin.2fa.verify'), ['code' => '000000']);
    } finally {
        Event::forget($retrievedEvent);
    }

    $response
        ->assertRedirect(route('admin.2fa.form'))
        ->assertSessionHasErrors('code')
        ->assertSessionMissing('admin_2fa_passed');

    expect($concurrentFailuresInjected)->toBeTrue()
        ->and($corretor->fresh()->first_login_verified_at)->toBeNull()
        ->and($challenge->fresh()->attempts)->toBe(3)
        ->and($challenge->fresh()->used_at)->toBeNull();
});

it('rejects an admin 2fa challenge invalidated by a concurrent resend after it is read', function () {
    $corretor = Corretor::query()->create([
        'name' => 'Administrador 2FA Reenvio Concorrente',
        'email' => 'admin-2fa-resend-race@example.test',
        'password' => 'safe-password',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
        'first_login_verified_at' => null,
    ]);

    $plainCode = '654321';
    $challenge = CorretorLoginVerificacaoCode::query()->create([
        'corretor_id' => $corretor->id,
        'code_hash' => Hash::make($plainCode),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 0,
        'used_at' => null,
        'ip_address' => '198.51.100.55',
        'user_agent' => 'Pest security test',
    ]);

    $attemptIp = '198.51.100.56';
    $verifyKey = "admin:2fa:verify:{$corretor->id}:{$attemptIp}";
    $retrievedEvent = 'eloquent.retrieved: '.CorretorLoginVerificacaoCode::class;
    $concurrentResendInjected = false;

    RateLimiter::clear($verifyKey);

    Event::listen(
        $retrievedEvent,
        function (CorretorLoginVerificacaoCode $retrievedChallenge) use (
            $challenge,
            &$concurrentResendInjected
        ): void {
            if ($concurrentResendInjected || ! $retrievedChallenge->is($challenge)) {
                return;
            }

            $concurrentResendInjected = true;

            CorretorLoginVerificacaoCode::query()
                ->whereKey($challenge->id)
                ->update(['used_at' => now()]);
        },
    );

    try {
        $response = $this->actingAs($corretor, 'admin')
            ->withServerVariables(['REMOTE_ADDR' => $attemptIp])
            ->from(route('admin.2fa.form'))
            ->post(route('admin.2fa.verify'), ['code' => $plainCode]);
    } finally {
        Event::forget($retrievedEvent);
    }

    $response
        ->assertRedirect(route('admin.2fa.form'))
        ->assertSessionHasErrors('code')
        ->assertSessionMissing('admin_2fa_passed');

    expect($concurrentResendInjected)->toBeTrue()
        ->and($corretor->fresh()->first_login_verified_at)->toBeNull()
        ->and($challenge->fresh()->attempts)->toBe(0)
        ->and($challenge->fresh()->used_at)->not->toBeNull();
});

it('accepts a valid admin 2fa code before the challenge attempt limit', function () {
    $corretor = Corretor::query()->create([
        'name' => 'Administrador 2FA Válido',
        'email' => 'admin-2fa-valid@example.test',
        'password' => 'safe-password',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
        'first_login_verified_at' => null,
    ]);

    $plainCode = '654321';
    $challenge = CorretorLoginVerificacaoCode::query()->create([
        'corretor_id' => $corretor->id,
        'code_hash' => Hash::make($plainCode),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 4,
        'used_at' => null,
        'ip_address' => '198.51.100.60',
        'user_agent' => 'Pest security test',
    ]);

    $attemptIp = '198.51.100.61';
    RateLimiter::clear("admin:2fa:verify:{$corretor->id}:{$attemptIp}");

    $this->actingAs($corretor, 'admin')
        ->withServerVariables(['REMOTE_ADDR' => $attemptIp])
        ->post(route('admin.2fa.verify'), ['code' => $plainCode])
        ->assertRedirect(route('Dashboard-Admin'))
        ->assertSessionHas('admin_2fa_passed', true)
        ->assertSessionHas('success');

    expect($corretor->fresh()->first_login_verified_at)->not->toBeNull()
        ->and($corretor->fresh()->last_login_at)->not->toBeNull()
        ->and($challenge->fresh()->attempts)->toBe(4)
        ->and($challenge->fresh()->used_at)->not->toBeNull();
});

it('invalidates an admin 2fa challenge on its fifth failed attempt', function () {
    $corretor = Corretor::query()->create([
        'name' => 'Administrador 2FA Esgotado',
        'email' => 'admin-2fa-exhausted@example.test',
        'password' => 'safe-password',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
        'first_login_verified_at' => null,
    ]);

    $challenge = CorretorLoginVerificacaoCode::query()->create([
        'corretor_id' => $corretor->id,
        'code_hash' => Hash::make('654321'),
        'expires_at' => now()->addMinutes(10),
        'attempts' => 4,
        'used_at' => null,
        'ip_address' => '198.51.100.70',
        'user_agent' => 'Pest security test',
    ]);

    $attemptIp = '198.51.100.71';
    RateLimiter::clear("admin:2fa:verify:{$corretor->id}:{$attemptIp}");

    $this->actingAs($corretor, 'admin')
        ->withServerVariables(['REMOTE_ADDR' => $attemptIp])
        ->from(route('admin.2fa.form'))
        ->post(route('admin.2fa.verify'), ['code' => '000000'])
        ->assertRedirect(route('admin.2fa.form'))
        ->assertSessionHasErrors('code')
        ->assertSessionMissing('admin_2fa_passed');

    expect($corretor->fresh()->first_login_verified_at)->toBeNull()
        ->and($challenge->fresh()->attempts)->toBe(5)
        ->and($challenge->fresh()->used_at)->not->toBeNull();
});

it('keeps an expired admin 2fa challenge unusable', function () {
    $corretor = Corretor::query()->create([
        'name' => 'Administrador 2FA Expirado',
        'email' => 'admin-2fa-expired@example.test',
        'password' => 'safe-password',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
        'first_login_verified_at' => null,
    ]);

    $plainCode = '654321';
    $challenge = CorretorLoginVerificacaoCode::query()->create([
        'corretor_id' => $corretor->id,
        'code_hash' => Hash::make($plainCode),
        'expires_at' => now()->subSecond(),
        'attempts' => 0,
        'used_at' => null,
        'ip_address' => '198.51.100.80',
        'user_agent' => 'Pest security test',
    ]);

    $attemptIp = '198.51.100.81';
    RateLimiter::clear("admin:2fa:verify:{$corretor->id}:{$attemptIp}");

    $this->actingAs($corretor, 'admin')
        ->withServerVariables(['REMOTE_ADDR' => $attemptIp])
        ->from(route('admin.2fa.form'))
        ->post(route('admin.2fa.verify'), ['code' => $plainCode])
        ->assertRedirect(route('admin.2fa.form'))
        ->assertSessionHasErrors('code')
        ->assertSessionMissing('admin_2fa_passed');

    expect($corretor->fresh()->first_login_verified_at)->toBeNull()
        ->and($challenge->fresh()->attempts)->toBe(0)
        ->and($challenge->fresh()->used_at)->not->toBeNull();
});
