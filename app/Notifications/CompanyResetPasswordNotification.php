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
        $url = url(route('company.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        return (new MailMessage)
            ->subject('Redefinição de senha - AkiAluga')
            ->line('Você solicitou a redefinição da senha da sua imobiliária.')
            ->action('Redefinir senha', $url)
            ->line("Este link expira em {$expiresInMinutes} minutos.")
            ->line('Se você não solicitou, ignore este e-mail.');
    }
}
