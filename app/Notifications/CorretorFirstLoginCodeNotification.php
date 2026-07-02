<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CorretorFirstLoginCodeNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private string $code,
        private string $expiresAt,
    )
    {}

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
        $nome = $notifiable->name ?? $notifiable->nome ?? 'Corretor';
        return (new MailMessage)
            ->subject('Código de verificação - Painel da Corretora')
            ->greeting("Olá, {$nome}!")
            ->line('Recebemos uma tentativa de primeiro acesso ao painel interno da corretora.')
            ->line('Use o código abaixo para confirmar sua identidade:')
            ->line("Código: {$this->code}")
            ->line("Este código expira às {$this->expiresAt}.")
            ->line('Se você não tentou acessar o sistema, ignore este e-mail e avise o administrador.');
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
