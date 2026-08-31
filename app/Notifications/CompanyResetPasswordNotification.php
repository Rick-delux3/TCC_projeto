<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyResetPasswordNotification extends Notification
{
    public function __construct(
        public string $token
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expiresInMinutes = (int) config('auth.passwords.companies.expire', 60);
        $activeBrandKey = config('branding.active', 'tcc');
        $brandName = config(
            "branding.profiles.{$activeBrandKey}.name",
            config('app.name', 'NVS Seguros')
        );
        $url = rtrim((string) config('app.url'), '/').route('company.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false);

        return (new MailMessage)
            ->subject("Redefinição de senha - {$brandName}")
            ->action('Redefinir senha', $url)
            ->view('emails.notifications.company-reset-password', [
                'company' => $notifiable,
                'resetUrl' => $url,
                'expiresInMinutes' => $expiresInMinutes,
                'subject' => "Redefinição de senha - {$brandName}",
            ]);
    }
}
