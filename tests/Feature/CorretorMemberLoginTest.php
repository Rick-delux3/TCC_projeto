<?php

use App\Models\Corretor;
use App\Models\CorretorLoginVerificacaoCode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Http::fake();
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
});

function invitedMember(array $overrides = []): Corretor
{
    return Corretor::query()->create(array_merge([
        'name' => 'Integrante de Teste',
        'email' => 'integrante@example.test',
        'cpf' => null,
        'password' => 'password',
        'role' => Corretor::ROLE_INTEGRANTE,
        'active' => true,
        'invited_at' => now(),
        'invite_version' => 1,
        'invite_send_count' => 1,
        'invite_expires_at' => now()->addHour(),
    ], $overrides));
}

it('renders an immediately usable member login without external calls', function () {
    $response = $this->get(route('admin.login', [
        'email' => 'prefill@example.test',
    ]));

    $response
        ->assertOk()
        ->assertSee('value="prefill@example.test"', false)
        ->assertSee('id="page-loader-modal"', false)
        ->assertSee('class="is-hidden"', false)
        ->assertSee('data-no-loader', false)
        ->assertDontSee('setTimeout(hideLoader', false);

    expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and(strtolower((string) $response->headers->get('Content-Security-Policy')))
        ->toContain("frame-ancestors 'none'")
        ->not->toContain('sandbox');

    Http::assertNothingSent();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('accepts a valid signed invitation before redirecting to the login form', function () {
    $member = invitedMember();

    $url = URL::temporarySignedRoute(
        'admin.member.invite.accept',
        now()->addHour(),
        ['corretor' => $member, 'version' => 1]
    );

    $this->get($url)
        ->assertRedirect(route('admin.login', ['email' => $member->email]))
        ->assertSessionHas('member_invite_corretor_id', $member->id)
        ->assertSessionHas('member_invite_version', 1);
});

it('rejects invalid, expired, and already used invitations', function (string $state) {
    $overrides = match ($state) {
        'expired' => ['invite_expires_at' => now()->subMinute()],
        'used' => ['invite_accepted_at' => now()],
        default => [],
    };

    $member = invitedMember($overrides);

    $url = $state !== 'invalid'
        ? URL::temporarySignedRoute(
            'admin.member.invite.accept',
            now()->addHour(),
            ['corretor' => $member, 'version' => 1]
        )
        : route('admin.member.invite.accept', [
            'corretor' => $member,
            'version' => 1,
        ]);

    $this->get($url)
        ->assertRedirect(route('admin.login'))
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('member_invite_corretor_id');
})->with([
    'invalid signature' => ['invalid'],
    'expired invitation state' => ['expired'],
    'already used invitation' => ['used'],
]);

it('does not authorize a pending member from an arbitrary email query string', function () {
    $member = invitedMember();

    $this->get(route('admin.login', ['email' => $member->email]))
        ->assertOk()
        ->assertSessionMissing('member_invite_corretor_id')
        ->assertSessionMissing('member_invite_version');

    $this->post(route('admin.member.login.post'), [
        'email' => $member->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');
});

it('logs in an accepted and verified member', function () {
    $member = invitedMember([
        'invite_accepted_at' => now(),
        'first_login_verified_at' => now(),
    ]);

    $this->post(route('admin.member.login.post'), [
        'email' => $member->email,
        'password' => 'password',
    ])->assertRedirect(route('Dashboard-Admin'));

    $this->assertAuthenticatedAs($member, 'admin');
});

it('accepts a pending invitation only after valid credentials and starts 2fa', function () {
    $member = invitedMember();

    $url = URL::temporarySignedRoute(
        'admin.member.invite.accept',
        now()->addHour(),
        ['corretor' => $member, 'version' => 1]
    );

    $this->get($url)->assertRedirect();

    $this->post(route('admin.member.login.post'), [
        'email' => $member->email,
        'password' => 'password',
    ])->assertRedirect(route('admin.2fa.form'));

    expect($member->fresh()->hasAcceptedInvitation())->toBeTrue();
    $this->assertAuthenticatedAs($member, 'admin');
    expect(
        CorretorLoginVerificacaoCode::query()
            ->where('corretor_id', $member->id)
            ->whereNull('used_at')
            ->exists()
    )->toBeTrue();
});
