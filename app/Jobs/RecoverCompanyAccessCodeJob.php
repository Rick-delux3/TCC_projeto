<?php

namespace App\Jobs;

use App\Models\Imobiliaria;
use App\Notifications\CompanyAcessCodeNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecoverCompanyAccessCodeJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $email)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $company = Imobiliaria::query()
            ->where('email', $this->email)
            ->where('lead_form_active', true)
            ->first(['id', 'name', 'email', 'lead_access_code']);

        if (! $company || blank($company->lead_access_code)) {
            return;
        }

        $company->notify(new CompanyAcessCodeNotification(
            companyName: $company->name,
            accessCode: $company->lead_access_code,
            accessUrl: rtrim((string) config('app.url'), '/').route(
                'simulation.registered-company.access', [], false,
            ),
            companyId: $company->getKey(),
        ));
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Falha ao processar a recuperação do código de acesso da imobiliária.', [
            'exception' => $exception::class,
        ]);
    }
}
