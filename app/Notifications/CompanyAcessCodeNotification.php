<?php

namespace App\Notifications;

use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompanyAcessCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
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
        $subject = "Boas-vindas à {$brandName} — código de acesso";

        return (new MailMessage)
            ->subject($subject)
            ->action('Abrir formulário', $this->accessUrl)
            ->view('emails.notifications.company-access-code', [
                'subject' => $subject,
                'companyName' => $this->companyName,
                'accessCode' => $this->accessCode,
                'accessUrl' => $this->accessUrl,
            ]);
    }

    public function failed(Throwable $exception): void
    {
        if ($this->companyId === null || $this->sentByCorretorId === null) {
            return;
        }

        if (Corretor::query()->whereKey($this->sentByCorretorId)->exists()) {
            CorretorActivityLog::query()->create([
                'corretor_id' => $this->sentByCorretorId,
                'action' => 'imobiliaria_acesso_email_falhou',
                'model_type' => Imobiliaria::class,
                'model_id' => $this->companyId,
                'new_values' => [
                    'exception' => $exception::class,
                ],
                'description' => 'Falha definitiva ao enviar o e-mail de boas-vindas da imobiliária.',
            ]);
        }

        Log::error('Falha definitiva ao enviar o e-mail de boas-vindas da imobiliária.', [
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
