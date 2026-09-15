<?php

namespace App\Services;

use App\Models\Corretor;
use App\Notifications\CorretorIntegranteLoginNotification;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Throwable;

class CorretorInvitationService
{
    public function sendOrResend(
        Corretor $integrante,
        Corretor $sentBy,
        Request $request
    ): void {
        if (! $sentBy->isCeo()) {
            throw new DomainException(
                'Apenas o CEO pode enviar convites.'
            );
        }

        $expirationHours = max(
            1,
            (int) config('admin.member_invitation_hours', 48)
        );

        $cooldownSeconds = max(
            0,
            (int) config(
                'admin.member_invitation_resend_cooldown_seconds',
                60
            )
        );

        $result = DB::transaction(function () use (
            $integrante,
            $sentBy,
            $request,
            $expirationHours,
            $cooldownSeconds
        ) {
            /*
            |--------------------------------------------------------------------------
            | Bloqueio para evitar dois reenvios simultâneos
            |--------------------------------------------------------------------------
            */
            $lockedIntegrante = Corretor::query()
                ->lockForUpdate()
                ->findOrFail($integrante->id);

            if (! $lockedIntegrante->isIntegrante()) {
                throw new DomainException(
                    'Convites são permitidos somente para integrantes.'
                );
            }

            if (! $lockedIntegrante->isActive()) {
                throw new DomainException(
                    'Ative o integrante antes de enviar o convite.'
                );
            }

            if (! filter_var($lockedIntegrante->email, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException(
                    'Cadastre um e-mail válido antes de enviar o convite.'
                );
            }

            if ($lockedIntegrante->hasAcceptedInvitation()) {
                throw new DomainException(
                    'Este integrante já aceitou o convite.'
                );
            }

            if (
                filled($lockedIntegrante->invite_last_sent_at)
                && $cooldownSeconds > 0
            ) {
                $availableAt = $lockedIntegrante
                    ->invite_last_sent_at
                    ->copy()
                    ->addSeconds($cooldownSeconds);

                if (now()->lt($availableAt)) {
                    $seconds = max(
                        1,
                        now()->diffInSeconds($availableAt)
                    );

                    throw new DomainException(
                        "Aguarde {$seconds} segundos antes de reenviar o convite."
                    );
                }
            }

            $isResend = (int) $lockedIntegrante->invite_send_count > 0;

            $previousValues = [
                'invite_version' => $lockedIntegrante->invite_version,
                'invite_expires_at' => optional(
                    $lockedIntegrante->invite_expires_at
                )?->toDateTimeString(),
                'invite_last_sent_at' => optional(
                    $lockedIntegrante->invite_last_sent_at
                )?->toDateTimeString(),
                'invite_send_count' => $lockedIntegrante->invite_send_count,
            ];

            $newVersion = (
                (int) $lockedIntegrante->invite_version
            ) + 1;

            $expiresAt = now()->addHours($expirationHours);

            $lockedIntegrante->forceFill([
                /*
                |--------------------------------------------------------------------------
                | Mantém a data e o autor do primeiro convite
                |--------------------------------------------------------------------------
                */
                'invited_at' => $lockedIntegrante->invited_at ?? now(),

                'invited_by_corretor_id' => $lockedIntegrante->invited_by_corretor_id
                    ?? $sentBy->id,

                /*
                |--------------------------------------------------------------------------
                | Dados da tentativa atual
                |--------------------------------------------------------------------------
                */
                'invite_version' => $newVersion,
                'invite_expires_at' => $expiresAt,
                'invite_last_sent_at' => now(),
                'invite_send_count' => (
                    (int) $lockedIntegrante->invite_send_count
                ) + 1,
            ])->save();

            $newValues = [
                'invite_version' => $newVersion,
                'invite_expires_at' => $expiresAt->toDateTimeString(),
                'invite_last_sent_at' => now()->toDateTimeString(),
                'invite_send_count' => $lockedIntegrante->invite_send_count,
            ];

            $this->registerActivity(
                actor: $sentBy,
                integrante: $lockedIntegrante,
                action: $isResend
                    ? 'integrante_convite_reenviado'
                    : 'integrante_convite_enviado',
                description: $isResend
                    ? 'Reenvio do convite adicionado à fila.'
                    : 'Primeiro convite adicionado à fila.',
                oldValues: $previousValues,
                newValues: $newValues,
                request: $request
            );

            return [
                'integrante' => $lockedIntegrante->fresh(),
                'expires_at' => $expiresAt,
                'version' => $newVersion,
                'is_resend' => $isResend,
            ];
        });

        /*
        |--------------------------------------------------------------------------
        | URL temporária assinada
        |--------------------------------------------------------------------------
        | O parâmetro version também é validado contra o banco.
        */
        $invitationUrl = URL::temporarySignedRoute(
            'admin.member.invite.accept',
            $result['expires_at'],
            [
                'corretor' => $result['integrante']->getRouteKey(),
                'version' => $result['version'],
            ]
        );

        try {
            $result['integrante']->notify(
                new CorretorIntegranteLoginNotification(
                    invitationUrl: $invitationUrl,
                    expiresAt: $result['expires_at'],
                    isResend: $result['is_resend'],
                    corretorId: $result['integrante']->id,
                    inviteVersion: $result['version'],
                    sentByCorretorId: $sentBy->id,
                )
            );
        } catch (Throwable $exception) {
            /*
            |--------------------------------------------------------------------------
            | Se nem sequer foi possível enfileirar a notificação
            |--------------------------------------------------------------------------
            | Invalida a tentativa para que o CEO possa tentar novamente.
            */
            Corretor::query()
                ->whereKey($result['integrante']->id)
                ->where('invite_version', $result['version'])
                ->update([
                    'invite_expires_at' => now()->subSecond(),
                    'invite_last_sent_at' => null,
                ]);

            $this->registerActivity(
                actor: $sentBy,
                integrante: $result['integrante'],
                action: 'integrante_convite_falhou',
                description: 'Falha ao enfileirar o convite do integrante.',
                oldValues: [],
                newValues: [
                    'exception' => $exception::class,
                ],
                request: $request
            );

            throw $exception;
        }
    }

    public function rememberValidInvitation(
        Request $request,
        Corretor $integrante,
        int $version
    ): void {
        $integrante->refresh();

        if (! $integrante->isIntegrante()) {
            throw new DomainException('Convite inválido.');
        }

        if (! $integrante->isActive()) {
            throw new DomainException(
                'O acesso deste integrante está desabilitado.'
            );
        }

        if ($integrante->hasAcceptedInvitation()) {
            throw new DomainException(
                'Este convite já foi utilizado. Faça login normalmente.'
            );
        }

        if ($integrante->invitationIsExpired()) {
            throw new DomainException(
                'Este convite expirou. Solicite um novo convite ao CEO.'
            );
        }

        if ($version !== (int) $integrante->invite_version) {
            throw new DomainException(
                'Este convite foi substituído por um convite mais recente.'
            );
        }

        $request->session()->put([
            'member_invite_corretor_id' => (int) $integrante->id,
            'member_invite_version' => $version,
        ]);
    }

    public function assertFirstLoginCanContinue(
        Request $request,
        Corretor $integrante
    ): void {
        if ($integrante->hasAcceptedInvitation()) {
            return;
        }

        if ($integrante->invitationIsExpired()) {
            throw new DomainException(
                'Seu convite expirou. Solicite ao CEO um novo convite.'
            );
        }

        $sessionCorretorId = (int) $request->session()->get(
            'member_invite_corretor_id'
        );

        $sessionVersion = (int) $request->session()->get(
            'member_invite_version'
        );

        if (
            $sessionCorretorId !== (int) $integrante->id
            || $sessionVersion !== (int) $integrante->invite_version
        ) {
            throw new DomainException(
                'Use o link de convite mais recente enviado ao seu e-mail.'
            );
        }
    }

    public function acceptAfterSuccessfulLogin(
        Request $request,
        Corretor $integrante
    ): void {
        if ($integrante->hasAcceptedInvitation()) {
            $this->forgetInvitationSession($request);

            return;
        }

        $sessionCorretorId = (int) $request->session()->get(
            'member_invite_corretor_id'
        );

        $sessionVersion = (int) $request->session()->get(
            'member_invite_version'
        );

        DB::transaction(function () use (
            $request,
            $integrante,
            $sessionCorretorId,
            $sessionVersion
        ) {
            $lockedIntegrante = Corretor::query()
                ->lockForUpdate()
                ->findOrFail($integrante->id);

            if ($lockedIntegrante->hasAcceptedInvitation()) {
                return;
            }

            if (
                $sessionCorretorId !== (int) $lockedIntegrante->id
                || $sessionVersion !== (int) $lockedIntegrante->invite_version
                || $lockedIntegrante->invitationIsExpired()
            ) {
                throw new DomainException(
                    'O convite expirou ou foi substituído. Solicite um novo convite.'
                );
            }

            $lockedIntegrante->forceFill([
                'invite_accepted_at' => now(),
            ])->save();

            $this->registerActivity(
                actor: $lockedIntegrante,
                integrante: $lockedIntegrante,
                action: 'integrante_convite_aceito',
                description: 'O integrante aceitou o convite.',
                oldValues: [
                    'invite_accepted_at' => null,
                ],
                newValues: [
                    'invite_accepted_at' => now()->toDateTimeString(),
                ],
                request: $request
            );
        });

        $this->forgetInvitationSession($request);
    }

    private function forgetInvitationSession(Request $request): void
    {
        $request->session()->forget([
            'member_invite_corretor_id',
            'member_invite_version',
        ]);
    }

    private function registerActivity(
        Corretor $actor,
        Corretor $integrante,
        string $action,
        string $description,
        array $oldValues,
        array $newValues,
        Request $request
    ): void {
        DB::table('logs_atividades_corretores')->insert([
            'corretor_id' => $actor->id,
            'action' => $action,
            'model_type' => Corretor::class,
            'model_id' => $integrante->id,
            'old_values' => json_encode(
                $oldValues,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'new_values' => json_encode(
                $newValues,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'description' => $description,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
