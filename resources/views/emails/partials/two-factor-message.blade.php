@php
    $activeBrandKey = config('branding.active', 'tcc');
    $brand = config("branding.profiles.{$activeBrandKey}", config('branding.profiles.tcc'));
    $isClientBrand = ($brand['key'] ?? 'tcc') === 'client';
    $brandName = $brand['name'] ?? config('app.name', 'NVS Seguros');
    $brandShortName = $brand['short_name'] ?? $brandName;
    $logoUrl = asset($brand['logo'] ?? 'imgs/Logo_NVS.png');
    $formattedCode = strlen((string) $code) === 6
        ? substr((string) $code, 0, 3).' '.substr((string) $code, 3)
        : (string) $code;

    $primaryColor = $isClientBrand ? '#00288f' : '#146fb6';
    $primaryDarkColor = $isClientBrand ? '#001650' : '#030133';
    $accentColor = $isClientBrand ? '#e6000b' : '#fd1e6e';
    $softColor = $isClientBrand ? '#edf3ff' : '#eef6ff';
    $borderColor = $isClientBrand ? '#d5deeb' : '#d8e1ec';
    $textColor = $isClientBrand ? '#14213d' : '#172033';
    $mutedColor = $isClientBrand ? '#53617a' : '#55658c';
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
            .email-header { padding: 24px 24px !important; }
            .email-content { padding: 34px 24px 32px !important; }
            .email-title { font-size: 32px !important; line-height: 38px !important; }
            .email-code { font-size: 42px !important; letter-spacing: 5px !important; }
            .email-code-cell { padding: 24px 12px !important; }
            .email-warning { padding: 20px !important; }
            .email-footer { padding: 24px !important; }
            .brand-logo-client { width: 280px !important; max-width: 100% !important; }
            .brand-logo-tcc { width: 138px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f6fb; color: {{ $textColor }}; font-family: 'Segoe UI', Arial, Helvetica, sans-serif; -webkit-font-smoothing: antialiased;">
    <div style="display: none; max-height: 0; overflow: hidden; opacity: 0; color: transparent; line-height: 1px; font-size: 1px;">
        {{ $preheader }}
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; background-color: #f3f6fb;">
        <tr>
            <td align="center" style="padding: 32px 12px;">
                <table role="presentation" class="email-shell" width="640" cellspacing="0" cellpadding="0" border="0" data-email-template="two-factor" style="width: 640px; max-width: 640px; overflow: hidden; background-color: #ffffff; border: 1px solid {{ $borderColor }}; border-radius: 12px; box-shadow: 0 18px 44px rgba(15, 35, 70, 0.10);">
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

                    <tr>
                        <td class="email-content" style="padding: 46px 54px 42px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="padding-bottom: 18px;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td width="34" height="34" align="center" valign="middle" style="width: 34px; height: 34px; color: {{ $primaryColor }}; background-color: {{ $softColor }}; border: 1px solid {{ $borderColor }}; border-radius: 8px; font-size: 11px; font-weight: 800; line-height: 34px; letter-spacing: -0.3px;">
                                                    2FA
                                                </td>
                                                <td style="padding-left: 12px; color: {{ $primaryColor }}; font-size: 13px; font-weight: 800; line-height: 18px; letter-spacing: 1.5px; text-transform: uppercase;">
                                                    {{ $eyebrow }}
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td class="email-title" style="padding-bottom: 28px; color: {{ $textColor }}; font-size: 40px; font-weight: 800; line-height: 46px; letter-spacing: -1.6px; text-align: left;">
                                        {{ $heading }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-bottom: 12px; color: {{ $textColor }}; font-size: 18px; font-weight: 700; line-height: 27px;">
                                        {{ $greeting }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-bottom: 28px; color: {{ $textColor }}; font-size: 17px; font-weight: 400; line-height: 27px;">
                                        {{ $introduction }}
                                    </td>
                                </tr>

                                <tr>
                                    <td style="padding-bottom: 18px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; background-color: {{ $softColor }}; border: 1px solid {{ $primaryColor }}; border-radius: 10px;">
                                            <tr>
                                                <td class="email-code-cell" align="center" style="padding: 28px 18px;">
                                                    <span class="email-code" style="display: inline-block; color: {{ $primaryColor }}; font-family: 'Courier New', Courier, monospace; font-size: 54px; font-weight: 700; line-height: 62px; letter-spacing: 8px; white-space: nowrap;">
                                                        {{ $formattedCode }}
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>

                                <tr>
                                    <td align="center" style="padding-bottom: 30px; color: {{ $primaryColor }}; font-size: 16px; font-weight: 700; line-height: 24px;">
                                        Este código expira às {{ $expiresAt }}.
                                    </td>
                                </tr>

                                <tr>
                                    <td class="email-warning" style="padding: 22px 24px; background-color: {{ $isClientBrand ? '#fff7f7' : '#fff5f8' }}; border: 1px solid {{ $isClientBrand ? '#f1b6bb' : '#f5b4cb' }}; border-left: 4px solid {{ $accentColor }}; border-radius: 10px;">
                                        <div style="padding-bottom: 6px; color: {{ $isClientBrand ? '#a60007' : '#a80f45' }}; font-size: 15px; font-weight: 800; line-height: 22px;">
                                            {{ $warningTitle }}
                                        </div>
                                        <div style="color: {{ $textColor }}; font-size: 14px; font-weight: 400; line-height: 22px;">
                                            {{ $warningText }}
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="email-footer" align="center" style="padding: 26px 32px; color: {{ $isClientBrand ? '#ffffff' : $mutedColor }}; background-color: {{ $isClientBrand ? $primaryColor : '#f8fafc' }}; border-top: 1px solid {{ $borderColor }}; font-size: 14px; font-weight: 600; line-height: 22px;">
                            {{ $brandShortName }} &nbsp;&bull;&nbsp; {{ $footerLabel }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 0; font-size: 0; line-height: 0;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td width="50%" height="6" style="height: 6px; background-color: {{ $primaryColor }};"></td>
                                    <td width="50%" height="6" style="height: 6px; background-color: {{ $accentColor }};"></td>
                                </tr>
                            </table>
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
