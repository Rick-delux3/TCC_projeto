<?php

namespace App\Notifications;

use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class CorretorIntegranteLoginNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public string $invitationUrl,
        public CarbonInterface $expiresAt,
        public bool $isResend = false,
        public ?int $corretorId = null,
        public ?int $inviteVersion = null,
        public ?int $sentByCorretorId = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
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
                .$this->expiresAt->format('d/m/Y \à\s H:i')
                .'.'
            )
            ->line(
                'Caso expire, solicite ao CEO um novo envio.'
            );
    }

    public function failed(Throwable $exception): void
    {
        if ($this->corretorId === null || $this->inviteVersion === null) {
            return;
        }

        $updated = Corretor::query()
            ->whereKey($this->corretorId)
            ->where('invite_version', $this->inviteVersion)
            ->whereNull('invite_accepted_at')
            ->whereNotNull('invite_last_sent_at')
            ->update([
                'invite_expires_at' => now()->subSecond(),
                'invite_last_sent_at' => null,
            ]);

        if (
            $updated > 0
            && $this->sentByCorretorId !== null
            && Corretor::query()->whereKey($this->sentByCorretorId)->exists()
        ) {
            CorretorActivityLog::query()->create([
                'corretor_id' => $this->sentByCorretorId,
                'action' => 'integrante_convite_falhou',
                'model_type' => Corretor::class,
                'model_id' => $this->corretorId,
                'new_values' => [
                    'invite_version' => $this->inviteVersion,
                    'exception' => $exception::class,
                ],
                'description' => 'Falha definitiva ao enviar o convite do integrante.',
            ]);
        }

        Log::error('Falha definitiva ao enviar convite do integrante.', [
            'corretor_id' => $this->corretorId,
            'invite_version' => $this->inviteVersion,
            'exception' => $exception::class,
            'mailer' => config('mail.default'),
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
