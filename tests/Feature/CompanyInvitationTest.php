<?php

use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Notifications\CompanyAcessCodeNotification;
use App\Services\CompanyInvitationService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config([
        'app.url' => 'https://app.example.test',
        'branding.active' => 'client',
    ]);

    Notification::fake();
});

function companyInvitationSender(array $overrides = []): Corretor
{
    return Corretor::query()->create(array_merge([
        'name' => 'Corretor Autorizado',
        'email' => 'corretor-convite@example.test',
        'password' => Hash::make('senha1234'),
        'role' => Corretor::ROLE_INTEGRANTE,
        'permissions' => [
            'imobiliarias.visualizar',
            'imobiliarias.cadastrar',
        ],
        'active' => true,
        'first_login_verified_at' => now(),
    ], $overrides));
}

function companyInvitationRecipient(array $overrides = []): Imobiliaria
{
    return Imobiliaria::query()->create(array_merge([
        'name' => 'Imobiliária Horizonte & Filhos',
        'email' => 'contato-horizonte@example.test',
        'phone' => '11999998888',
        'cnpj' => '11222333000181',
        'cep' => '01001000',
        'password' => Hash::make('senha1234'),
        'city' => 'São Paulo',
        'state' => 'SP',
        'lead_form_active' => true,
        'lead_access_code' => 'ACESSO7',
    ], $overrides));
}

it('queues an encrypted welcome email after commit with the company access code', function () {
    $sender = companyInvitationSender();
    $company = companyInvitationRecipient();
    $request = Request::create(
        '/admin/imobiliarias',
        'POST',
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Pest Browser',
        ],
    );

    app(CompanyInvitationService::class)->sendWelcomeAccessCode(
        company: $company,
        sentBy: $sender,
        request: $request,
    );

    Notification::assertSentTo(
        $company,
        CompanyAcessCodeNotification::class,
        function (CompanyAcessCodeNotification $notification) use ($company, $sender): bool {
            $queuedNotification = new SendQueuedNotifications(
                $company,
                $notification,
            );
            $mail = $notification->toMail($company);
            $html = $mail->render();

            expect($notification)
                ->toBeInstanceOf(ShouldQueue::class)
                ->toBeInstanceOf(ShouldBeEncrypted::class)
                ->and($notification->companyName)->toBe($company->name)
                ->and($notification->accessCode)->toBe($company->lead_access_code)
                ->and($notification->companyId)->toBe($company->id)
                ->and($notification->sentByCorretorId)->toBe($sender->id)
                ->and($notification->accessUrl)->toBe('https://app.example.test/simulacao/imobiliaria-cadastrada')
                ->not->toContain($company->lead_access_code)
                ->and($queuedNotification->shouldBeEncrypted)->toBeTrue()
                ->and($queuedNotification->afterCommit)->toBeTrue()
                ->and($queuedNotification->tries)->toBe(3)
                ->and($queuedNotification->timeout)->toBe(30)
                ->and($notification->backoff())->toBe([60, 300])
                ->and($mail->view)->toBe('emails.notifications.company-access-code')
                ->and($mail->actionUrl)->toBe($notification->accessUrl)
                ->and($mail->viewData['companyName'])->toBe($company->name)
                ->and($mail->viewData['accessCode'])->toBe($company->lead_access_code)
                ->and($html)->toContain('Imobiliária Horizonte &amp; Filhos')
                ->toContain('ACESSO7')
                ->toContain('data-email-template="company-welcome"');

            return true;
        },
    );

    $activity = CorretorActivityLog::query()
        ->where('action', 'imobiliaria_acesso_email_enfileirado')
        ->firstOrFail();

    expect($activity)
        ->corretor_id->toBe($sender->id)
        ->model_id->toBe($company->id)
        ->ip->toBe('203.0.113.10')
        ->user_agent->toBe('Pest Browser')
        ->and(json_encode($activity->new_values))
        ->not->toContain($company->lead_access_code)
        ->not->toContain($company->email);
});

it('rejects invalid recipients before queuing or recording a successful send', function () {
    $sender = companyInvitationSender();
    $company = companyInvitationRecipient([
        'email' => 'destinatario-invalido',
    ]);

    expect(fn () => app(CompanyInvitationService::class)->sendWelcomeAccessCode(
        company: $company,
        sentBy: $sender,
        request: Request::create('/admin/imobiliarias', 'POST'),
    ))->toThrow(DomainException::class, 'destinatário é inválido');

    Notification::assertNothingSent();
    $this->assertDatabaseMissing('logs_atividades_corretores', [
        'action' => 'imobiliaria_acesso_email_enfileirado',
        'model_id' => $company->id,
    ]);
});

it('does not let an unauthorized broker invoke the invitation service directly', function () {
    $sender = companyInvitationSender([
        'permissions' => [],
    ]);
    $company = companyInvitationRecipient();

    expect(fn () => app(CompanyInvitationService::class)->sendWelcomeAccessCode(
        company: $company,
        sentBy: $sender,
        request: Request::create('/admin/imobiliarias', 'POST'),
    ))->toThrow(DomainException::class, 'não possui permissão');

    Notification::assertNothingSent();
    $this->assertDatabaseCount('logs_atividades_corretores', 0);
});

it('records a definitive delivery failure without logging provider secrets', function () {
    Log::spy();

    $sender = companyInvitationSender();
    $company = companyInvitationRecipient();
    $notification = new CompanyAcessCodeNotification(
        companyName: $company->name,
        accessCode: $company->lead_access_code,
        accessUrl: 'https://app.example.test/simulacao/imobiliaria-cadastrada',
        companyId: $company->id,
        sentByCorretorId: $sender->id,
    );
    $providerDetails = 'MAIL_PASSWORD=segredo resposta privada do provedor';

    $notification->failed(new RuntimeException($providerDetails));

    $this->assertDatabaseHas('logs_atividades_corretores', [
        'corretor_id' => $sender->id,
        'action' => 'imobiliaria_acesso_email_falhou',
        'model_id' => $company->id,
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
