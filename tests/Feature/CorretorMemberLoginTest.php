<?php

use App\Models\Corretor;
use App\Models\CorretorLoginVerificacaoCode;
use App\Notifications\CorretorIntegranteLoginNotification;
use App\Services\CorretorInvitationService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

it('queues encrypted invitation email after commit with bounded retries', function () {
    $ceo = Corretor::query()->create([
        'name' => 'CEO de Teste',
        'email' => 'ceo@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
    ]);

    $member = invitedMember([
        'invite_version' => 0,
        'invite_send_count' => 0,
        'invite_expires_at' => null,
        'invite_last_sent_at' => null,
    ]);

    app(CorretorInvitationService::class)->sendOrResend(
        integrante: $member,
        sentBy: $ceo,
        request: Request::create('/admin/equipe', 'POST'),
    );

    Notification::assertSentTo(
        $member,
        CorretorIntegranteLoginNotification::class,
        function (CorretorIntegranteLoginNotification $notification) use ($ceo, $member): bool {
            $queuedNotification = new SendQueuedNotifications(
                $member,
                $notification,
            );

            expect($notification)
                ->toBeInstanceOf(ShouldQueue::class)
                ->toBeInstanceOf(ShouldBeEncrypted::class)
                ->and($notification->corretorId)->toBe($member->id)
                ->and($notification->inviteVersion)->toBe(1)
                ->and($notification->sentByCorretorId)->toBe($ceo->id)
                ->and($queuedNotification->shouldBeEncrypted)->toBeTrue()
                ->and($queuedNotification->afterCommit)->toBeTrue()
                ->and($queuedNotification->tries)->toBe(3)
                ->and($queuedNotification->timeout)->toBe(30)
                ->and($notification->backoff())->toBe([60, 300]);

            return true;
        },
    );
});

it('keeps member registration and resend functional through the ceo routes', function () {
    config([
        'admin.member_invitation_resend_cooldown_seconds' => 0,
    ]);

    $ceo = Corretor::query()->create([
        'name' => 'CEO Funcional',
        'email' => 'ceo-functional@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
        'first_login_verified_at' => now(),
    ]);

    $this->actingAs($ceo, 'admin')
        ->post(route('admin.config-equipe.store'), [
            'nome' => 'Integrante Funcional',
            'email' => 'member-functional@example.test',
            'password' => 'safe-password',
            'password_confirmation' => 'safe-password',
            'permissions' => [],
            'active' => '1',
        ])
        ->assertRedirect(route('admin.config-equipe.index'))
        ->assertSessionHas(
            'success',
            'Integrante cadastrado e convite adicionado à fila de envio.',
        );

    $member = Corretor::query()
        ->where('email', 'member-functional@example.test')
        ->firstOrFail();

    expect($member->invite_version)->toBe(1)
        ->and($member->invite_send_count)->toBe(1)
        ->and($member->hasValidPendingInvitation())->toBeTrue();

    Notification::assertSentToTimes(
        $member,
        CorretorIntegranteLoginNotification::class,
        1,
    );

    $this->actingAs($ceo, 'admin')
        ->from(route('admin.config-equipe.index'))
        ->post(route('admin.config-equipe.resend-invitation', $member))
        ->assertRedirect(route('admin.config-equipe.index'))
        ->assertSessionHas(
            'success',
            "Novo convite adicionado à fila de envio para {$member->email}.",
        );

    $member->refresh();

    expect($member->invite_version)->toBe(2)
        ->and($member->invite_send_count)->toBe(2)
        ->and($member->hasValidPendingInvitation())->toBeTrue();

    Notification::assertSentToTimes(
        $member,
        CorretorIntegranteLoginNotification::class,
        2,
    );
});

it('rejects an invalid invitation recipient before changing invitation state', function () {
    $ceo = Corretor::query()->create([
        'name' => 'CEO de Teste',
        'email' => 'ceo@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
    ]);

    $member = invitedMember([
        'email' => 'invalid-recipient',
        'invite_version' => 0,
        'invite_send_count' => 0,
        'invite_expires_at' => null,
        'invite_last_sent_at' => null,
    ]);

    expect(fn () => app(CorretorInvitationService::class)->sendOrResend(
        integrante: $member,
        sentBy: $ceo,
        request: Request::create('/admin/equipe', 'POST'),
    ))->toThrow(DomainException::class);

    expect($member->fresh()->invite_version)->toBe(0)
        ->and($member->fresh()->invite_send_count)->toBe(0);

    Notification::assertNothingSent();
});

it('allows retry after definitive invitation delivery failure without logging provider details', function () {
    Log::spy();

    $ceo = Corretor::query()->create([
        'name' => 'CEO de Teste',
        'email' => 'ceo@example.test',
        'password' => 'password',
        'role' => Corretor::ROLE_CEO,
        'active' => true,
    ]);

    $member = invitedMember([
        'invite_version' => 3,
        'invite_last_sent_at' => now(),
        'invite_expires_at' => now()->addHours(48),
    ]);

    $notification = new CorretorIntegranteLoginNotification(
        invitationUrl: 'https://example.test/signed-invitation',
        expiresAt: now()->addHours(48),
        corretorId: $member->id,
        inviteVersion: 3,
        sentByCorretorId: $ceo->id,
    );

    $providerDetails = 'RESEND_API_KEY=re_secret provider response';

    $notification->failed(new RuntimeException($providerDetails));

    $member->refresh();

    expect($member->invite_last_sent_at)->toBeNull()
        ->and($member->invite_expires_at->isPast())->toBeTrue();

    $this->assertDatabaseHas('logs_atividades_corretores', [
        'corretor_id' => $ceo->id,
        'action' => 'integrante_convite_falhou',
        'model_id' => $member->id,
    ]);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($providerDetails): bool {
            $loggedData = json_encode([$message, $context]);

            return is_string($loggedData)
                && ! str_contains($loggedData, $providerDetails)
                && $context['exception'] === RuntimeException::class;
        });
});

it('does not invalidate a newer invitation when an older queued delivery fails', function () {
    Log::spy();

    $member = invitedMember([
        'invite_version' => 4,
        'invite_last_sent_at' => now(),
        'invite_expires_at' => now()->addHours(48),
    ]);

    $originalLastSentAt = $member->invite_last_sent_at;
    $originalExpiresAt = $member->invite_expires_at;

    $notification = new CorretorIntegranteLoginNotification(
        invitationUrl: 'https://example.test/old-signed-invitation',
        expiresAt: now()->addHour(),
        corretorId: $member->id,
        inviteVersion: 3,
    );

    $notification->failed(new RuntimeException('delivery failed'));

    $member->refresh();

    expect($member->invite_version)->toBe(4)
        ->and($member->invite_last_sent_at->equalTo($originalLastSentAt))->toBeTrue()
        ->and($member->invite_expires_at->equalTo($originalExpiresAt))->toBeTrue();
});

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
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
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

it('does not disclose a pending invitation before credentials are valid', function () {
    $member = invitedMember();

    $response = $this->post(route('admin.member.login.post'), [
        'email' => $member->email,
        'password' => 'incorrect-password',
    ]);

    $response
        ->assertSessionHasErrors([
            'email' => 'E-mail ou senha incorretos.',
        ])
        ->assertSessionDoesntHaveErrors('password');
});

it('does not accept one member invitation with another member credentials', function () {
    $firstMember = invitedMember();
    $secondMember = invitedMember([
        'email' => 'outro-integrante@example.test',
    ]);

    $url = URL::temporarySignedRoute(
        'admin.member.invite.accept',
        now()->addHour(),
        ['corretor' => $firstMember, 'version' => 1]
    );

    $this->get($url)->assertRedirect();

    $this->post(route('admin.member.login.post'), [
        'email' => $secondMember->email,
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    expect($secondMember->fresh()->hasAcceptedInvitation())->toBeFalse();
    $this->assertGuest('admin');
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
