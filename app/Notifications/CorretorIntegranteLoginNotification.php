<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

class CorretorIntegranteLoginNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $invitationUrl,
        public \Carbon\CarbonInterface $expiresAt,
        public bool $isResend = false
    )
    {
        $this->afterCommit();
    }

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
        ->subject(
            $this->isResend
                ? 'Novo convite para acessar o painel'
                : 'Convite para acessar o painel'
        )
        ->greeting("Olá, {$notifiable->name}!")
        ->line(
            $this->isResend
                ? 'Um novo convite foi gerado. Qualquer link anterior deixou de ser válido.'
                : 'Você foi convidado para integrar a equipe da corretora.'
        )
        ->action('Acessar convite', $this->invitationUrl)
        ->line(
            'Este convite expira em '
            . $this->expiresAt->format('d/m/Y \à\s H:i')
            . '.'
        )
        ->line(
            'Caso expire, solicite ao CEO um novo envio.'
        );
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
