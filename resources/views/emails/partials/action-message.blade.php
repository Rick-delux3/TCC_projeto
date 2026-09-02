@php
    $activeBrandKey = config('branding.active', 'tcc');
    $brand = config("branding.profiles.{$activeBrandKey}", config('branding.profiles.tcc'));
    $isClientBrand = ($brand['key'] ?? 'tcc') === 'client';
    $brandName = $brand['name'] ?? config('branding.profiles.tcc.name', config('app.name'));
    $brandShortName = $brand['short_name'] ?? $brandName;
    $logoUrl = asset($brand['logo_email'] ?? $brand['logo'] ?? config('branding.profiles.tcc.logo_email'));
    $colors = $brand['colors'] ?? config('branding.profiles.tcc.colors');

    $primaryColor = $colors['blue'];
    $primaryDarkColor = $colors['primary_dark'];
    $accentColor = $colors['accent'];
    $softColor = $colors['primary_soft'];
    $borderColor = $colors['border'];
    $textColor = $colors['text'];
    $mutedColor = $colors['text_muted'];
    $backgroundColor = $colors['background'];
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
        @media only screen and (max-width: 680px) {
            .email-shell { width: 100% !important; }
            .email-header { padding: 24px !important; }
            .email-content { padding: 34px 22px 32px !important; }
            .email-heading { font-size: 31px !important; line-height: 38px !important; }
            .email-panel { padding: 22px !important; }
            .email-badge-cell { display: block !important; width: 100% !important; padding: 0 0 16px !important; }
            .email-panel-copy { display: block !important; width: 100% !important; }
            .email-expiry-label { display: block !important; width: 100% !important; padding: 0 0 8px !important; }
            .email-expiry-value { display: block !important; width: 100% !important; text-align: left !important; }
            .email-footer { padding: 24px !important; }
            .brand-logo-client { width: 280px !important; max-width: 100% !important; }
            .brand-logo-tcc { width: 138px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: {{ $backgroundColor }}; color: {{ $textColor }}; font-family: Arial, Helvetica, sans-serif; -webkit-font-smoothing: antialiased;">
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; color: transparent; line-height: 1px; font-size: 1px;">
        {{ $preheader }}
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; background-color: {{ $backgroundColor }};">
        <tr>
            <td align="center" style="padding: 32px 12px;">
                <table role="presentation" class="email-shell" width="640" cellspacing="0" cellpadding="0" border="0" data-email-template="notification-action" style="width: 640px; max-width: 640px; overflow: hidden; background-color: #ffffff; border: 1px solid {{ $borderColor }}; border-radius: 12px; box-shadow: 0 18px 44px rgba(15, 35, 70, 0.10);">
                    <tr>
                        <td style="padding: 0; font-size: 0; line-height: 0;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td width="{{ $isClientBrand ? '76%' : '50%' }}" height="8" style="height: 8px; background-color: {{ $primaryColor }};"></td>
                                    <td width="{{ $isClientBrand ? '24%' : '50%' }}" height="8" style="height: 8px; background-color: {{ $accentColor }};"></td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="email-header" align="center" style="padding: {{ $isClientBrand ? '30px 40px' : '26px 40px' }}; background-color: {{ $isClientBrand ? '#ffffff' : $primaryDarkColor }}; border-bottom: 1px solid {{ $borderColor }};">
                            <img
                                src="{{ $logoUrl }}"
                                width="{{ $isClientBrand ? '340' : '164' }}"
                                class="{{ $isClientBrand ? 'brand-logo-client' : 'brand-logo-tcc' }}"
                                alt="{{ $brandName }}"
                                style="display: block; width: {{ $isClientBrand ? '340px' : '164px' }}; max-width: 100%; height: auto; border: 0;"
                            >
                        </td>
                    </tr>

                    @if ($isClientBrand)
                        <tr>
                            <td align="center" style="padding: 25px 24px; color: #ffffff; background-color: {{ $primaryColor }}; font-size: 22px; font-weight: 800; line-height: 28px; letter-spacing: 0.7px; text-transform: uppercase;">
                                {{ $eyebrow }}
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td class="email-content" style="padding: 44px 48px 42px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                @unless ($isClientBrand)
                                    <tr>
                                        <td align="center" style="padding-bottom: 22px;">
                                            <span style="display: inline-block; padding: 10px 16px; color: {{ $primaryColor }}; background-color: {{ $softColor }}; border: 1px solid {{ $borderColor }}; border-radius: 7px; font-size: 13px; font-weight: 800; line-height: 18px; letter-spacing: 0.8px; text-transform: uppercase;">
                                                {{ $eyebrow }}
                                            </span>
                                        </td>
                                    </tr>
                                @endunless

                                <tr>
                                    <td class="email-heading" align="center" style="padding-bottom: 30px; color: {{ $textColor }}; font-size: 38px; font-weight: 800; line-height: 45px; letter-spacing: -1.3px;">
                                        {{ $heading }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-bottom: 10px; color: {{ $textColor }}; font-size: 18px; font-weight: 800; line-height: 27px;">
                                        {{ $greeting }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-bottom: 28px; color: {{ $textColor }}; font-size: 16px; font-weight: 400; line-height: 26px;">
                                        {{ $introduction }}
                                    </td>
                                </tr>

                                <tr>
                                    <td class="email-panel" style="padding: 26px; background-color: {{ $softColor }}; border: 1px solid {{ $borderColor }}; border-radius: 10px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td class="email-badge-cell" width="72" valign="top" style="width: 72px; padding-right: 18px;">
                                                    <table role="presentation" width="72" cellspacing="0" cellpadding="0" border="0" style="width: 72px; background-color: #ffffff; border: 1px solid {{ $borderColor }}; border-radius: 10px;">
                                                        <tr>
                                                            <td width="72" height="62" align="center" valign="middle" style="width: 72px; height: 62px; color: {{ $primaryColor }}; font-size: {{ strlen($badgeLabel) > 3 ? '10px' : '18px' }}; font-weight: 900; line-height: 18px; letter-spacing: 0.4px;">
                                                                {{ $badgeLabel }}
                                                            </td>
                                                        </tr>
                                                    </table>
                                                </td>
                                                <td class="email-panel-copy" valign="middle">
                                                    <div style="padding-bottom: 5px; color: {{ $primaryColor }}; font-size: 13px; font-weight: 800; line-height: 18px; letter-spacing: 0.7px; text-transform: uppercase;">
                                                        {{ $panelKicker }}
                                                    </div>
                                                    <div style="color: {{ $textColor }}; font-size: 20px; font-weight: 800; line-height: 27px;">
                                                        {{ $panelTitle }}
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan="2" style="padding-top: 22px;">
                                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; background-color: {{ $primaryColor }}; border-radius: 8px;">
                                                        <tr>
                                                            <td align="center" style="padding: 0;">
                                                                <a href="{{ $actionUrl }}" target="_blank" rel="noopener" style="display: block; padding: 17px 22px; color: #ffffff; font-size: 18px; font-weight: 800; line-height: 24px; text-align: center; text-decoration: none;">
                                                                    {{ $actionText }}
                                                                </a>
                                                            </td>
                                                            @if ($isClientBrand)
                                                                <td width="8" style="width: 8px; background-color: {{ $accentColor }}; border-radius: 0 8px 8px 0;"></td>
                                                            @endif
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-top: 24px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; border: 1px solid {{ $borderColor }}; border-radius: 10px;">
                                            <tr>
                                                <td class="email-expiry-label" width="48%" style="padding: 22px 12px 22px 24px; color: {{ $textColor }}; font-size: 15px; line-height: 22px;">
                                                    {{ $expirationLabel }}
                                                </td>
                                                <td class="email-expiry-value" align="right" style="padding: 22px 24px 22px 12px; color: {{ $primaryColor }}; font-size: 18px; font-weight: 800; line-height: 24px;">
                                                    {{ $expirationValue }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-top: 24px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; background-color: {{ $softColor }}; border: 1px solid {{ $borderColor }}; border-left: 4px solid {{ $primaryColor }}; border-radius: 8px;">
                                            <tr>
                                                <td style="padding: 18px 20px; color: {{ $primaryColor }}; font-size: 14px; font-weight: 600; line-height: 22px;">
                                                    {{ $helperText }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-top: 24px; color: {{ $mutedColor }}; font-size: 12px; line-height: 18px;">
                                        Se o botão não funcionar, copie e cole este endereço no navegador:<br>
                                        <a href="{{ $actionUrl }}" target="_blank" rel="noopener" style="color: {{ $primaryColor }}; text-decoration: underline; word-break: break-all;">
                                            {{ $actionUrl }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="email-footer" align="center" style="padding: 26px 32px; color: #ffffff; background-color: {{ $primaryDarkColor }}; border-top: 1px solid {{ $borderColor }}; font-size: 14px; font-weight: 600; line-height: 22px;">
                            {{ $brandShortName }} &nbsp;&bull;&nbsp; {{ $footerLabel }}
                        </td>
                    </tr>
                </table>

                <table role="presentation" class="email-shell" width="640" cellspacing="0" cellpadding="0" border="0" style="width: 640px; max-width: 640px;">
                    <tr>
                        <td align="center" style="padding: 18px 24px 0; color: #7b879b; font-size: 12px; line-height: 18px;">
                            Esta é uma mensagem automática de segurança. Não responda a este e-mail.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
