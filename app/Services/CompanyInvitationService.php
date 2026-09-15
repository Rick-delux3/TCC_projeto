<?php

namespace App\Services;

use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Imobiliaria;
use App\Notifications\CompanyAcessCodeNotification;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class CompanyInvitationService
{
    public function sendWelcomeAccessCode(
        Imobiliaria $company,
        Corretor $sentBy,
        Request $request,
    ): void {
        if (! Gate::forUser($sentBy)->allows('create-real-estate-company')) {
            throw new DomainException(
                'o corretor não possui permissão para enviar o e-mail de boas-vindas.',
            );
        }

        $companyToNotify = DB::transaction(function () use (
            $company,
            $sentBy,
            $request,
        ): Imobiliaria {
            $lockedCompany = Imobiliaria::query()
                ->lockForUpdate()
                ->findOrFail($company->getKey());

            if (! filter_var($lockedCompany->email, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException(
                    'o e-mail de boas-vindas não pôde ser preparado porque o destinatário é inválido.',
                );
            }

            if (! is_string($lockedCompany->lead_access_code)
                || blank(trim($lockedCompany->lead_access_code))) {
                throw new DomainException(
                    'o e-mail de boas-vindas não pôde ser preparado porque o código de acesso não está disponível.',
                );
            }

            CorretorActivityLog::query()->create([
                'corretor_id' => $sentBy->getKey(),
                'action' => 'imobiliaria_acesso_email_enfileirado',
                'model_type' => Imobiliaria::class,
                'model_id' => $lockedCompany->getKey(),
                'new_values' => [
                    'notification' => CompanyAcessCodeNotification::class,
                    'lead_form_active' => (bool) $lockedCompany->lead_form_active,
                ],
                'description' => 'E-mail de boas-vindas e código de acesso adicionado à fila.',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $lockedCompany;
        });

        try {
            $companyToNotify->notify(
                new CompanyAcessCodeNotification(
                    companyName: $companyToNotify->name,
                    accessCode: $companyToNotify->lead_access_code,
                    accessUrl: $this->registeredCompanyAccessUrl(),
                    companyId: $companyToNotify->getKey(),
                    sentByCorretorId: $sentBy->getKey(),
                ),
            );
        } catch (Throwable $exception) {
            CorretorActivityLog::query()->create([
                'corretor_id' => $sentBy->getKey(),
                'action' => 'imobiliaria_acesso_email_falhou',
                'model_type' => Imobiliaria::class,
                'model_id' => $companyToNotify->getKey(),
                'new_values' => [
                    'exception' => $exception::class,
                ],
                'description' => 'Falha ao adicionar o e-mail de boas-vindas à fila.',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            throw $exception;
        }
    }

    private function registeredCompanyAccessUrl(): string
    {
        $applicationUrl = rtrim((string) config('app.url'), '/');

        if (filter_var($applicationUrl, FILTER_VALIDATE_URL) === false) {
            throw new DomainException(
                'o endereço público do formulário não está configurado corretamente.',
            );
        }

        return $applicationUrl.route(
            'simulation.registered-company.access',
            [],
            false,
        );
    }
}
