<?php

use App\Models\Imobiliaria;
use App\Notifications\CompanyAcessCodeNotification;
use App\Notifications\CompanyResetPasswordNotification;
use App\Notifications\CorretorFirstLoginCodeNotification;
use App\Notifications\CorretorIntegranteLoginNotification;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config([
        'app.url' => 'https://app.example.test',
        'branding.active' => 'tcc',
    ]);
});

it('renders the welcome email with the correct brand and escapes company data', function (string $profile, string $logo, string $footer) {
    config(['branding.active' => $profile]);
    $notification = new CompanyAcessCodeNotification(
        companyName: '<script>alert(1)</script> Horizonte & Filhos',
        accessCode: 'ABC234',
        accessUrl: 'https://app.example.test/simulacao/imobiliaria-cadastrada',
    );
    $html = $notification->toMail(new Imobiliaria)->render();

    expect($html)->toContain('data-brand="'.$profile.'"')
        ->toContain(asset($logo))
        ->toContain($footer)
        ->toContain('ABC234')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; Horizonte &amp; Filhos')
        ->toContain('https://app.example.test/simulacao/imobiliaria-cadastrada')
        ->not->toContain('<script>')
        ->not->toContain('Código ilustrativo')
        ->not->toContain('IMOB-')
        ->and(substr_count($html, '<h1 '))->toBe(1);
})->with([
    ['client', 'imgs/logo-akialuga.jpg', 'Aki Aluga · NEVES corretora de seguros'],
    ['tcc', 'imgs/Logo_NVS.png', 'NVS Seguros · Portal imobiliário'],
]);

it('does not render executable URLs in the company welcome email', function () {
    $notification = new CompanyAcessCodeNotification(
        companyName: 'Imobiliária Horizonte',
        accessCode: 'ABC234',
        accessUrl: 'javascript:alert(1)',
    );

    expect($notification->toMail(new Imobiliaria)->render())
        ->not->toContain('href="javascript:')
        ->toContain('ABC234');
});

it('renders the member invitation with a dedicated branded view', function () {
    $notification = new CorretorIntegranteLoginNotification(
        invitationUrl: 'https://app.example.test/convite-assinado',
        expiresAt: CarbonImmutable::parse('2026-08-31 18:30:00', 'UTC'),
    );

    $mail = $notification->toMail((object) ['name' => 'Ricardo Neves']);
    $html = $mail->render();

    expect($mail->view)
        ->toBe('emails.notifications.corretor-integrante-invitation')
        ->and($mail->actionUrl)->toBe('https://app.example.test/convite-assinado')
        ->and($html)->toContain('data-email-template="notification-action"')
        ->toContain('Você foi convidado para fazer parte da equipe')
        ->toContain('Olá, Ricardo Neves!')
        ->toContain('31/08/2026 às 18:30 UTC')
        ->toContain(asset('imgs/Logo_NVS.png'));
});

it('clearly identifies a resent invitation and invalidates the previous link in the copy', function () {
    $notification = new CorretorIntegranteLoginNotification(
        invitationUrl: 'https://app.example.test/novo-convite-assinado',
        expiresAt: CarbonImmutable::parse('2026-09-01 10:15:00', 'UTC'),
        isResend: true,
    );

    $mail = $notification->toMail((object) ['name' => 'Ricardo Neves']);
    $html = $mail->render();

    expect($mail->subject)->toBe('Novo convite para acessar o painel')
        ->and($html)->toContain('Seu novo convite está pronto')
        ->toContain('Qualquer link enviado anteriormente deixou de ser válido.')
        ->toContain('01/09/2026 às 10:15 UTC');
});

it('renders the first login code through the shared two-factor frontend', function () {
    $notification = new CorretorFirstLoginCodeNotification(
        code: '482917',
        expiresAt: '18:40 UTC',
    );

    $mail = $notification->toMail((object) ['name' => 'Ricardo Neves']);
    $html = $mail->render();

    expect($mail->view)
        ->toBe('emails.notifications.corretor-first-login-code')
        ->and($html)->toContain('data-email-template="two-factor"')
        ->toContain('Olá, Ricardo Neves.')
        ->toContain('482 917')
        ->toContain('Este código expira às 18:40 UTC.');
});

it('renders the company password reset with the active client brand', function () {
    config(['branding.active' => 'client']);

    $company = new Imobiliaria([
        'name' => 'Imobiliária Horizonte',
        'email' => 'contato@horizonte.example',
    ]);

    $mail = (new CompanyResetPasswordNotification('secure-token'))
        ->toMail($company);
    $html = $mail->render();

    expect($mail->view)
        ->toBe('emails.notifications.company-reset-password')
        ->and($mail->subject)->toBe('Redefinição de senha - Aki Aluga')
        ->and(parse_url($mail->actionUrl, PHP_URL_HOST))->toBe('app.example.test')
        ->and($html)->toContain('data-email-template="notification-action"')
        ->toContain('Crie uma nova senha')
        ->toContain('Olá, Imobiliária Horizonte!')
        ->toContain('60 minutos')
        ->toContain(asset('imgs/logo-akialuga.jpg'));
});

it('keeps every notification email isolated to the active brand', function (
    string $profile,
    string $brandName,
    string $expectedLogo,
    string $unexpectedLogo,
    string $expectedPrimaryColor,
    string $unexpectedPrimaryColor,
) {
    config(['branding.active' => $profile]);

    $recipient = (object) ['name' => 'Ricardo Neves'];
    $company = new Imobiliaria([
        'name' => 'Imobiliária Horizonte',
        'email' => 'contato@horizonte.example',
    ]);

    $messages = [
        (new CorretorIntegranteLoginNotification(
            invitationUrl: 'https://app.example.test/convite-assinado',
            expiresAt: CarbonImmutable::parse('2026-08-31 18:30:00', 'UTC'),
        ))->toMail($recipient),
        (new CorretorIntegranteLoginNotification(
            invitationUrl: 'https://app.example.test/novo-convite-assinado',
            expiresAt: CarbonImmutable::parse('2026-09-01 10:15:00', 'UTC'),
            isResend: true,
        ))->toMail($recipient),
        (new CorretorFirstLoginCodeNotification(
            code: '482917',
            expiresAt: '18:40 UTC',
        ))->toMail($recipient),
        (new CompanyResetPasswordNotification('secure-token'))->toMail($company),
    ];

    foreach ($messages as $message) {
        $html = $message->render();

        expect($html)
            ->toContain($brandName)
            ->toContain(asset($expectedLogo))
            ->toContain($expectedPrimaryColor)
            ->not->toContain(asset($unexpectedLogo))
            ->not->toContain($unexpectedPrimaryColor)
            ->toContain('@media only screen and (max-width: 680px)');
    }

    expect($messages[3]->subject)->toBe("Redefinição de senha - {$brandName}");
})->with([
    'tcc / NVS' => [
        'tcc',
        'NVS Seguros',
        'imgs/Logo_NVS.png',
        'imgs/logo-akialuga.jpg',
        '#146FB6',
        '#00288F',
    ],
    'client / Aki Aluga' => [
        'client',
        'Aki Aluga',
        'imgs/logo-akialuga.jpg',
        'imgs/Logo_NVS.png',
        '#00288F',
        '#146FB6',
    ],
]);
