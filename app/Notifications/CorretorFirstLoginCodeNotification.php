<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CorretorFirstLoginCodeNotification extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(
        private string $code,
        private string $expiresAt,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Código de verificação - Painel da Corretora')
            ->view('emails.notifications.corretor-first-login-code', [
                'admin' => $notifiable,
                'code' => $this->code,
                'expiresAt' => $this->expiresAt,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
