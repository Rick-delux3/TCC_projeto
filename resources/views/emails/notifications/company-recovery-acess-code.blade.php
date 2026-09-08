@php
    $isClient = config('branding.active', 'tcc') === 'client';
    $profile = $isClient ? 'client' : 'tcc';
    $brand = config("branding.profiles.{$profile}");
    $blue = $isClient ? '#00288F' : '#0049BE';
    $navy = $isClient ? '#07143D' : '#030133';
    $accent = $isClient ? '#E6000B' : '#FD1E6E';
    $safeAccessUrl = filter_var($accessUrl, FILTER_VALIDATE_URL)
        && in_array(strtolower((string) parse_url($accessUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
        ? $accessUrl : null;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>{{ $subject }}</title>
    <style>
        a:focus-visible { outline: 3px solid {{ $accent }}; outline-offset: 4px; }
        @media only screen and (max-width: 600px) {
            .welcome-outer { padding: 16px 8px !important; }
            .welcome-content { padding: 28px 20px 22px !important; }
            .welcome-logo-cell { padding: 22px 18px !important; }
            .welcome-logo-client { width: 100% !important; max-width: 380px !important; }
            .welcome-logo-tcc { width: 170px !important; }
            .welcome-title { font-size: 32px !important; line-height: 38px !important; }
            .welcome-copy { font-size: 17px !important; line-height: 27px !important; }
            .welcome-code-panel { padding: 22px 12px !important; }
            .welcome-code { font-size: 30px !important; line-height: 42px !important; letter-spacing: 3px !important; }
            .welcome-code-label { font-size: 14px !important; line-height: 22px !important; }
            .welcome-notice { padding: 16px !important; }
            .welcome-footer { padding: 22px 16px !important; font-size: 15px !important; }
            .welcome-button { padding: 16px 22px !important; font-size: 18px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #F3F6FC; color: {{ $navy }}; font-family: Arial, Helvetica, sans-serif;">
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; font-size: 1px; line-height: 1px;">Conforme solicitado, enviamos novamente o código de acesso da sua imobiliária.</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; table-layout: fixed; background-color: #F3F6FC;">
        <tr>
            <td class="welcome-outer" align="center" style="padding: 32px 16px;">
                <!--[if mso]><table role="presentation" width="680" align="center"><tr><td><![endif]-->
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" data-email-template="company-recovery" data-brand="{{ $profile }}" style="max-width: 680px; table-layout: fixed; background-color: #FFFFFF; border: 1px solid #D8E1EC; border-radius: {{ $isClient ? '0' : '6px' }};">
                    @if ($isClient)
                        @include('emails.partials.company-welcome-stripe', ['firstWidth' => '76%', 'stripeBlue' => $blue, 'stripeAccent' => $accent])
                    @endif
                    <tr>
                        <td class="welcome-logo-cell" align="center" bgcolor="{{ $isClient ? '#FFFFFF' : '#030133' }}" style="padding: 20px 40px; background-color: {{ $isClient ? '#FFFFFF' : '#030133' }}; border-bottom: 1px solid #D8E1EC;">
                            <img src="{{ asset($brand['logo_email']) }}" alt="{{ $brand['name'] }}" width="{{ $isClient ? '480' : '190' }}" class="welcome-logo-{{ $profile }}" style="display: block; width: {{ $isClient ? '480px' : '190px' }}; max-width: 100%; height: auto; border: 0;">
                        </td>
                    </tr>
                    @unless ($isClient)
                        @include('emails.partials.company-welcome-stripe', ['firstWidth' => '50%', 'stripeBlue' => '#146FB6', 'stripeAccent' => $accent])
                    @endunless
                    <tr>
                        <td class="welcome-content" style="padding: 32px 50px 24px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="table-layout: fixed;">
                                <tr><td align="center" style="padding-bottom: 28px;">
                                    <h1 class="welcome-title" style="margin: 0; color: {{ $navy }}; font-size: {{ $isClient ? '42px' : '52px' }}; line-height: {{ $isClient ? '50px' : '60px' }}; font-weight: 700; letter-spacing: -1.3px;">Seu código<br>de acesso</h1>
                                </td></tr>
                                <tr><td class="welcome-copy" style="padding-bottom: 22px; font-size: 20px; line-height: 28px; overflow-wrap: anywhere; word-wrap: break-word;"><strong>Olá, equipe da {{ $companyName }}.</strong></td></tr>
                                <tr><td class="welcome-copy" style="padding-bottom: 24px; font-size: 20px; line-height: 29px;">Recebemos uma solicitação de reenvio do código de acesso da sua imobiliária. Confira abaixo o seu código atual.</td></tr>
                                <tr><td class="welcome-code-panel" align="center" style="padding: 24px 16px; background-color: #F3F6FC; border: 1px solid {{ $isClient ? '#AFC3FF' : '#4C85E4' }}; border-radius: 11px;">
                                    <p class="welcome-code-label" style="margin: 0 0 16px; color: {{ $blue }}; font-size: 17px; line-height: 24px; font-weight: 700;">CÓDIGO DE ACESSO DA IMOBILIÁRIA</p>
                                    <p class="welcome-code" style="margin: 0; color: {{ $blue }}; font-family: Consolas, 'Courier New', monospace; font-size: 48px; line-height: 60px; font-weight: 700; letter-spacing: 7px; overflow-wrap: anywhere; word-break: break-all;">{{ $accessCode }}</p>
                                    <p style="margin: 10px 0 0; color: #53617A; font-size: 15px; line-height: 22px;">Seu código de acesso permanece o mesmo.</p>
                                </td></tr>
                                <tr><td style="padding-top: 28px;">
                                    <h2 style="margin: 0 0 14px; color: {{ $isClient ? $blue : $navy }}; font-size: 25px; line-height: 32px;">Como acessar</h2>
                                    <ol class="welcome-copy" style="margin: 0; padding-left: 25px; font-size: 18px; line-height: 30px;">
                                        <li>Abra o formulário pelo botão abaixo.</li>
                                        <li>Informe o código de acesso da imobiliária.</li>
                                        <li>Preencha e envie sua solicitação.</li>
                                    </ol>
                                </td></tr>
                                @if ($safeAccessUrl)
                                    <tr><td align="center" style="padding: 24px 0 20px;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" bgcolor="{{ $blue }}" style="background-color: {{ $blue }}; border-radius: {{ $isClient ? '5px' : '9px' }}; mso-padding-alt: 16px 40px;">
                                            <a class="welcome-button" href="{{ $safeAccessUrl }}" target="_blank" rel="noopener noreferrer" style="display: inline-block; padding: 16px 40px; color: #FFFFFF; font-size: 20px; line-height: 26px; font-weight: 700; text-decoration: none;">Acessar formulários</a>
                                        </td></tr></table>
                                    </td></tr>
                                @endif
                                <tr><td class="welcome-notice" style="padding: 16px 22px; background-color: {{ $isClient ? '#F3F6FC' : '#FFF3F7' }}; border: 1px solid {{ $isClient ? '#AFC3FF' : '#FF9ABB' }}; border-radius: 10px;">
                                    <p style="margin: 0 0 4px; color: {{ $isClient ? $navy : '#D9004C' }}; font-size: 18px; line-height: 25px; font-weight: 700;">Mantenha seu código protegido.</p>
                                    <p style="margin: 0; color: {{ $navy }}; font-size: 17px; line-height: 25px;">Compartilhe apenas com a equipe autorizada da sua imobiliária.</p>
                                </td></tr>
                                <tr><td style="padding-top: 18px; font-size: 17px; line-height: 26px;">Se você não solicitou este reenvio, pode desconsiderar este e-mail. Nenhuma alteração foi feita no seu código de acesso.</td></tr>
                            </table>
                        </td>
                    </tr>
                    <tr><td class="welcome-footer" align="center" style="padding: 22px 24px; background-color: {{ $isClient ? $blue : '#F3F6FC' }}; color: {{ $isClient ? '#FFFFFF' : '#14213D' }}; border-top: 1px solid #D8E1EC; font-size: 17px; line-height: 25px; font-weight: {{ $isClient ? '700' : '400' }};">{{ $isClient ? 'Aki Aluga · NEVES corretora de seguros' : 'NVS Seguros · Portal imobiliário' }}</td></tr>
                    @unless ($isClient)
                        @include('emails.partials.company-welcome-stripe', ['firstWidth' => '50%', 'stripeBlue' => '#146FB6', 'stripeAccent' => $accent])
                    @endunless
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
