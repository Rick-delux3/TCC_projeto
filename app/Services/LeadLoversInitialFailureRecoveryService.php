<?php

namespace App\Services;

use App\Events\DashboardActivityChanged;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Lead;
use App\Support\LeadLoversInitialFailureCatalog;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LeadLoversInitialFailureRecoveryService
{
    public function __construct(
        private LeadLoversInitialFailureCatalog $failureCatalog
    ) {}

    public function correctAndRetry(
        Lead $lead,
        array $data,
        ?Corretor $corretor,
        ?int $companyId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $dispatchData = DB::transaction(function () use (
            $lead,
            $data,
            $corretor,
            $companyId,
            $ip,
            $userAgent,
        ): array {
            /*
             * lockForUpdate impede dois cliques concorrentes de
             * gerarem dois reenvios para o mesmo lead.
             */
            $lockedLead = Lead::query()
                ->whereKey($lead->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $failure = $this->failureCatalog->describe(
                $lockedLead
            );

            if (
                ! $failure['failed']
                || ! $failure['not_sent']
                || $failure['http_status'] !== 400
                || ! $failure['correctable']
                || $failure['fields'] === []
            ) {
                throw new DomainException(
                    'Este lead não possui mais uma falha corrigível.'
                );
            }

            if (
                $companyId !== null
                && (int) $lockedLead->company_id !== $companyId
            ) {
                throw new DomainException(
                    'Você não tem permissão para corrigir este lead.'
                );
            }

            /*
             * Segurança adicional: um lead que já recebeu ID remoto
             * nunca deve voltar ao fluxo de criação por este serviço.
             */
            if (
                $lockedLead->leadlovers_lead_id !== null
                || filled($lockedLead->sent_to_leadlovers_at)
            ) {
                throw new DomainException(
                    'Este lead já possui identificação na LeadLovers.'
                );
            }

            $allowedFields = $failure['fields'];

            foreach ($allowedFields as $field) {
                if (! array_key_exists($field, $data)) {
                    throw new DomainException(
                        "O campo obrigatório {$field} não foi informado."
                    );
                }
            }

            /*
             * Arr::only garante que somente os campos mapeados
             * serão alterados, mesmo que o serviço seja chamado
             * por outro ponto da aplicação no futuro.
             */
            $corrections = $this->validateCorrections(
                $lockedLead,
                Arr::only(
                    $data,
                    $allowedFields
                ),
                $allowedFields
            );

            $previousErrorCode = $failure['error_code'];
            $correctedFields = array_keys($corrections);

            $lockedLead->forceFill($corrections);

            /*
             * Invalida qualquer job antigo de atualização que ainda
             * possa estar na fila.
             */
            $nextUpdateVersion =
                (int) $lockedLead->leadlovers_update_version + 1;

            $lockedLead->forceFill([
                'leadlovers_status' => 'pending',
                'leadlovers_lead_id' => null,
                'sent_to_leadlovers_at' => null,

                'leadlovers_response' => [
                    'success' => false,
                    'phase' => 'ready_to_create',
                    'operation' => 'lead_creation',
                    'recovery' => [
                        'previous_error_code' => $previousErrorCode,
                        'corrected_fields' => $correctedFields,
                        'requested_at' => now()->toIso8601String(),
                    ],
                ],

                'leadlovers_initial_error_status' => null,
                'leadlovers_initial_error_code' => null,
                'leadlovers_initial_error_operation' => null,
                'leadlovers_initial_error_detail' => null,
                'leadlovers_initial_failed_at' => null,

                /*
                 * As correções já serão incluídas no novo payload
                 * de criação. Portanto, não existe atualização
                 * remota posterior pendente.
                 */
                'leadlovers_update_status' => 'idle',
                'leadlovers_update_version' => $nextUpdateVersion,
                'leadlovers_update_response' => null,
                'leadlovers_update_error' => null,
                'leadlovers_update_requested_at' => null,
                'leadlovers_update_at' => null,

                'updated_by_corretor_id' => $corretor?->id
                    ?? $lockedLead->updated_by_corretor_id,
            ])->save();

            if ($corretor !== null) {
                CorretorActivityLog::query()->create([
                    'corretor_id' => $corretor->id,
                    'action' => 'leadlovers_initial_send_correction_requested',
                    'model_type' => Lead::class,
                    'model_id' => $lockedLead->id,

                    /*
                     * Não duplicamos telefone/e-mail no log.
                     * Registramos somente quais campos mudaram.
                     */
                    'old_values' => [
                        'leadlovers_status' => 'failed',
                        'error_code' => $previousErrorCode,
                    ],
                    'new_values' => [
                        'leadlovers_status' => 'pending',
                        'corrected_fields' => $correctedFields,
                    ],

                    'description' => 'Corretor corrigiu dados recusados pela LeadLovers e solicitou um novo envio.',

                    'ip' => $ip,
                    'user_agent' => $userAgent,
                ]);
            } else {
                Log::notice(
                    'Imobiliaria corrigiu dados recusados pela LeadLovers.',
                    [
                        'lead_id' => $lockedLead->id,
                        'company_id' => $companyId,
                        'previous_error_code' => $previousErrorCode,
                        'corrected_fields' => $correctedFields,
                    ]
                );
            }

            return [
                'lead_id' => (int) $lockedLead->id,
                'company_id' => $lockedLead->company_id !== null
                    ? (int) $lockedLead->company_id
                    : null,
            ];
        });

        DashboardActivityChanged::dispatch(
            'lead',
            $dispatchData['lead_id'],
            $dispatchData['company_id'],
            'lead.sync.retrying',
        );

        try {
            $job = new SendLeadToLeadLoversJob(
                leadId: $dispatchData['lead_id']
            );

            $job->onQueue('leadlovers')
                ->afterCommit();

            Bus::dispatch($job);
        } catch (Throwable $exception) {
            $this->markQueueDispatchFailure(
                $dispatchData['lead_id']
            );

            Log::warning(
                'Falha ao colocar a correção do lead na fila da LeadLovers.',
                [
                    'lead_id' => $dispatchData['lead_id'],
                    'exception' => $exception::class,
                ]
            );

            DashboardActivityChanged::dispatch(
                'lead',
                $dispatchData['lead_id'],
                $dispatchData['company_id'],
                'lead.sync.failed',
            );

            throw new DomainException(
                'Os dados foram corrigidos, mas o reenvio não pôde ser colocado na fila.',
                0,
                $exception
            );
        }

        return [
            'lead_id' => $dispatchData['lead_id'],
            'message' => 'Dados corrigidos. O lead foi colocado novamente na fila da LeadLovers.',
        ];
    }

    private function validateCorrections(
        Lead $lead,
        array $corrections,
        array $allowedFields,
    ): array {
        if ($allowedFields === ['tel']) {
            $phone = preg_replace(
                '/\D/',
                '',
                (string) ($corrections['tel'] ?? '')
            );

            if (preg_match('/\A\d{10,11}\z/', $phone) !== 1) {
                throw new DomainException(
                    'O telefone corrigido deve conter 10 ou 11 dígitos.'
                );
            }

            $currentPhone = preg_replace('/\D/', '', (string) $lead->tel);

            if ($phone === $currentPhone) {
                throw new DomainException(
                    'Informe um telefone diferente daquele que foi recusado.'
                );
            }

            return ['tel' => $phone];
        }

        if ($allowedFields === ['email']) {
            $email = mb_strtolower(trim(
                (string) ($corrections['email'] ?? '')
            ));

            if (
                filter_var($email, FILTER_VALIDATE_EMAIL) === false
                || mb_strlen($email) > 255
            ) {
                throw new DomainException(
                    'Informe um e-mail corrigido válido.'
                );
            }

            if (
                $email === mb_strtolower(trim((string) $lead->email))
            ) {
                throw new DomainException(
                    'Informe um e-mail diferente daquele que foi recusado.'
                );
            }

            $conflict = Lead::query()
                ->whereKeyNot($lead->getKey())
                ->whereRaw('LOWER(email) = ?', [$email]);

            if ($lead->company_id !== null) {
                $conflict->where('company_id', $lead->company_id);
            } else {
                $conflict
                    ->whereNull('company_id')
                    ->where('origem', $lead->origem);
            }

            if ($conflict->exists()) {
                throw new DomainException(
                    'Este e-mail já pertence a outro lead do sistema.'
                );
            }

            return ['email' => $email];
        }

        throw new DomainException(
            'A falha não possui campos de correção válidos.'
        );
    }

    private function markQueueDispatchFailure(
        int $leadId
    ): void {
        DB::transaction(function () use ($leadId): void {
            $lead = Lead::query()
                ->whereKey($leadId)
                ->where('leadlovers_status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($lead === null) {
                return;
            }

            $lead->forceFill([
                'leadlovers_status' => 'failed',

                'leadlovers_response' => [
                    'success' => false,
                    'phase' => 'failed',
                    'operation' => 'queue_dispatch',
                    'error_code' => 'LOCAL_QUEUE_DISPATCH_FAILED',
                ],

                'leadlovers_initial_error_status' => null,
                'leadlovers_initial_error_code' => 'LOCAL_QUEUE_DISPATCH_FAILED',
                'leadlovers_initial_error_operation' => 'queue_dispatch',
                'leadlovers_initial_error_detail' => 'O reenvio não pôde ser colocado na fila.',
                'leadlovers_initial_failed_at' => now(),
            ])->save();
        });
    }
}
