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
    public function __construct()
    {
        //
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

        $loginUrl = Route::has('admin.login') ? route('admin.login') : url('/admin/login/form');

        return (new MailMessage)
            ->subject('Acesso ao painel da corretora')
            ->greeting('Olá ' . ($notifiable->nome ?? 'integrante') . '!')
            ->line('Você foi cadastrado como integrante da equipe da corretora!')
            ->line('Use o email cadastrado para acessar o painel.')
            ->action('Acessar painel', $loginUrl)
            ->line('Caso você não reconheça este cadastro, ignore este e-mail.');
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
