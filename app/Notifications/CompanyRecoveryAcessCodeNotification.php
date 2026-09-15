<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompanyRecoveryAcessCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $companyName,
        public string $accessCode,
        public string $accessUrl,
        public ?int $companyId = null,
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
        $activeBrandKey = config('branding.active', 'tcc');
        $brandName = config(
            "branding.profiles.{$activeBrandKey}.name",
            config('branding.profiles.tcc.name', config('app.name')),
        );
        $subject = "Reenvio do código de acesso — {$brandName}";

        return (new MailMessage)
            ->subject($subject)
            ->action('Abrir formulário', $this->accessUrl)
            ->view('emails.notifications.company-recovery-acess-code', [
                'subject' => $subject,
                'companyName' => $this->companyName,
                'accessCode' => $this->accessCode,
                'accessUrl' => $this->accessUrl,
            ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Falha definitiva ao reenviar o código de acesso da imobiliária.', [
            'company_id' => $this->companyId,
            'corretor_id' => $this->sentByCorretorId,
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
