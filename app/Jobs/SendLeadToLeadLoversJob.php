<?php

namespace App\Jobs;

use App\Exceptions\LeadLoversApiException;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Services\LeadLoversApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendLeadToLeadLoversJob implements ShouldQueue
{
    use Queueable;

    private const PHASE_READY_TO_CREATE = 'ready_to_create';

    private const PHASE_CREATE_STARTED = 'lead_creation_started';

    private const PHASE_RECONCILIATION_PENDING = 'lead_reconciliation_pending';

    private const PHASE_LEAD_PERSISTED = 'lead_persisted';

    private const PHASE_MACHINE_STARTED = 'machine_request_started';

    private const PHASE_MACHINE_PENDING = 'machine_confirmation_pending';

    private const PHASE_MACHINE_CONFLICT = 'machine_conflict_pending';

    private const RECONCILIATION_EMAIL_EXISTS = 'email_exists';

    private const RECONCILIATION_AMBIGUOUS_CREATE = 'ambiguous_create';

    public int $tries = 12;

    public int $timeout = 120;

    public function __construct(
        public int $leadId
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('leadlovers:initial:'.$this->leadId))
                ->releaseAfter($this->confirmationDelay())
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(LeadLoversApiClient $leadLovers): void
    {
        if (! config('services.leadlovers.enabled', false)) {
            $this->disableInitialSend();

            return;
        }

        if (! $this->claimInitialSend()) {
            return;
        }

        $lead = $this->loadLead();
        $machine = $this->machineConfigurationForLead($lead);

        if ($machine === null) {
            Log::warning('Configuracao de maquina LeadLovers invalida para o envio inicial.', [
                'lead_id' => $lead->id,
                'tipo_solicitante' => $lead->tipo_solicitante,
            ]);

            $this->failInitialSend(
                (int) $lead->id,
                'sequence_failed',
                $this->failureSummary('machine_configuration', null),
                'O envio inicial aguarda a correcao da configuracao de maquina e sequencia.'
            );

            return;
        }

        $remoteLeadId = $this->positiveInteger($lead->leadlovers_lead_id);
        $freshlyCreated = false;

        if ($remoteLeadId === null) {
            $mainTagId = $this->mainTagIdForLead($lead);

            if ($mainTagId === null) {
                Log::warning('Tag principal nao encontrada para o lead.', [
                    'lead_id' => $lead->id,
                    'tipo_solicitante' => $lead->tipo_solicitante,
                    'company_id' => $lead->company_id,
                ]);

                $this->failInitialSend(
                    (int) $lead->id,
                    'tag_failed',
                    $this->failureSummary('main_tag', null),
                    'O envio inicial aguarda a correcao da tag principal.'
                );

                return;
            }

            $resolved = $this->resolveRemoteLead(
                $leadLovers,
                $lead,
                $mainTagId
            );

            if ($resolved === null) {
                return;
            }

            $remoteLeadId = $resolved['lead_id'];
            $freshlyCreated = $resolved['created'];
        }

        $this->synchronizeMachine(
            $leadLovers,
            $remoteLeadId,
            $machine,
            $freshlyCreated
        );
    }

    /**
     * @return array{lead_id: int, created: bool}|null
     */
    private function resolveRemoteLead(
        LeadLoversApiClient $leadLovers,
        Lead $lead,
        int $mainTagId
    ): ?array {
        $phase = $this->currentPhase($lead);

        if (in_array($phase, [
            self::PHASE_CREATE_STARTED,
            self::PHASE_RECONCILIATION_PENDING,
        ], true)) {
            $email = $this->storedCreationEmail($lead);

            if ($email === null) {
                $this->permanentlyFail(
                    'lead_search',
                    null,
                    'O e-mail original da criacao nao pode ser recuperado com seguranca.'
                );

                return null;
            }

            $reason = $this->currentReconciliationReason($lead)
                ?? self::RECONCILIATION_AMBIGUOUS_CREATE;

            return $this->reconcileRemoteLead(
                $leadLovers,
                $email,
                $reason
            );
        }

        $email = trim((string) $lead->email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->failInitialSend(
                (int) $lead->id,
                'failed',
                $this->failureSummary('lead_creation', null),
                'O e-mail do lead nao permite concluir o envio inicial.'
            );

            return null;
        }

        $encryptedEmail = $this->encryptCreationEmail($email);

        if ($encryptedEmail === null) {
            $this->permanentlyFail(
                'lead_creation',
                null,
                'O e-mail da criacao nao pode ser protegido para uma retomada segura.'
            );

            return null;
        }

        if (! $this->storeProgress(self::PHASE_CREATE_STARTED, [
            'operation' => 'lead_creation',
            'creation_email_encrypted' => $encryptedEmail,
        ]) || ! $this->isStillProcessing()) {
            return null;
        }

        try {
            $response = $leadLovers->createLead(
                $this->creationPayload($lead, $mainTagId)
            );
        } catch (LeadLoversApiException $exception) {
            if ($this->isEmailExists($exception)) {
                if (! $this->storeProgress(self::PHASE_RECONCILIATION_PENDING, [
                    'operation' => 'lead_search',
                    'reconciliation_reason' => self::RECONCILIATION_EMAIL_EXISTS,
                    'creation_email_encrypted' => $encryptedEmail,
                    'status_code' => $exception->statusCode,
                    'error_code' => $exception->errorCode,
                ])) {
                    return null;
                }

                return $this->reconcileRemoteLead(
                    $leadLovers,
                    $email,
                    self::RECONCILIATION_EMAIL_EXISTS
                );
            }

            if ($exception->errorCode === 'LOCAL_RATE_LIMIT') {
                if (! $this->storeProgress(self::PHASE_READY_TO_CREATE, [
                    'operation' => 'lead_creation',
                    'error_code' => $exception->errorCode,
                ])) {
                    return null;
                }
                $this->retryOrFail(
                    'lead_creation',
                    $exception,
                    'O envio inicial excedeu as tentativas antes de criar o lead remoto.'
                );

                return null;
            }

            if ($exception->isTransient) {
                if (! $this->storeProgress(self::PHASE_RECONCILIATION_PENDING, [
                    'operation' => 'lead_search',
                    'reconciliation_reason' => self::RECONCILIATION_AMBIGUOUS_CREATE,
                    'creation_email_encrypted' => $encryptedEmail,
                    'status_code' => $exception->statusCode,
                    'error_code' => $exception->errorCode,
                ])) {
                    return null;
                }
                $this->retryOrFail(
                    'lead_creation',
                    $exception,
                    'Nao foi possivel conciliar o resultado incerto da criacao do lead.'
                );

                return null;
            }

            $this->permanentlyFail(
                'lead_creation',
                $exception,
                'A LeadLovers recusou a criacao do lead.'
            );

            return null;
        }

        $remoteLeadId = $this->positiveInteger($response['leadId'] ?? null);

        if ($remoteLeadId === null) {
            $this->retryOrFail(
                'lead_creation',
                null,
                'A resposta de criacao nao permitiu identificar o lead remoto.'
            );

            return null;
        }

        if (! $this->persistRemoteLeadId($remoteLeadId, 'created')) {
            return null;
        }

        return [
            'lead_id' => $remoteLeadId,
            'created' => true,
        ];
    }

    /**
     * @return array{lead_id: int, created: bool}|null
     */
    private function reconcileRemoteLead(
        LeadLoversApiClient $leadLovers,
        string $email,
        string $reason
    ): ?array {
        try {
            $result = $leadLovers->searchLeads([
                'page' => 1,
                'pageSize' => 10,
                'filters' => [
                    'staticFields' => [
                        'email' => [$email],
                    ],
                ],
            ]);
        } catch (LeadLoversApiException $exception) {
            if ($exception->isTransient) {
                $this->retryOrFail(
                    'lead_search',
                    $exception,
                    'A conciliacao do lead remoto excedeu as tentativas configuradas.'
                );

                return null;
            }

            $this->permanentlyFail(
                'lead_search',
                $exception,
                'Nao foi possivel conciliar o lead remoto por e-mail.'
            );

            return null;
        }

        $match = $this->uniqueExactSearchMatch($result, $email);

        if ($match['outcome'] !== 'matched') {
            if (
                $match['outcome'] === 'missing'
                && $reason === self::RECONCILIATION_AMBIGUOUS_CREATE
            ) {
                $this->retryOrFail(
                    'lead_search',
                    null,
                    'O resultado incerto da criacao nao apareceu na busca remota.'
                );

                return null;
            }

            $this->permanentlyFail(
                'lead_search',
                null,
                'A busca remota nao retornou uma correspondencia unica e exata.'
            );

            return null;
        }

        $remoteLeadId = $match['lead_id'];

        if (! $this->persistRemoteLeadId($remoteLeadId, 'reconciled')) {
            return null;
        }

        return [
            'lead_id' => $remoteLeadId,
            'created' => false,
        ];
    }

    /**
     * @param  array{machine_id: int, sequence_id: int, level: int}  $machine
     */
    private function synchronizeMachine(
        LeadLoversApiClient $leadLovers,
        int $remoteLeadId,
        array $machine,
        bool $freshlyCreated
    ): void {
        $lead = Lead::query()->findOrFail($this->leadId);
        $phase = $this->currentPhase($lead);
        $mustOnlyConfirm = in_array($phase, [
            self::PHASE_MACHINE_STARTED,
            self::PHASE_MACHINE_PENDING,
            self::PHASE_MACHINE_CONFLICT,
        ], true);

        if (! $freshlyCreated || $mustOnlyConfirm) {
            $machines = $this->remoteMachinesOrNull(
                $leadLovers,
                $remoteLeadId,
                'machine_confirmation'
            );

            if ($machines === null) {
                return;
            }

            if ($this->hasExpectedMachine($machines, $machine)) {
                $this->finishInitialSend($remoteLeadId, $machine);

                return;
            }

            if ($mustOnlyConfirm) {
                if (! $this->storeProgress(
                    $phase,
                    $this->machineProgress($remoteLeadId, $machine)
                )) {
                    return;
                }
                $this->retryOrFail(
                    'machine_confirmation',
                    null,
                    'A associacao do lead a maquina nao foi confirmada a tempo.',
                    [
                        'expected_machine' => $machine,

                        'remote_machine_count' => count($machines),

                        'remote_machines' => collect($machines)
                            ->take(10)
                            ->map(fn (array $remoteMachines): array => [
                                'machine_id' => $remoteMachines['id'] ?? null,
                                'level' => $remoteMachines['level'] ?? null,
                                'sequence_id' => data_get(
                                    $remoteMachines,
                                    'sequence.id'
                                ),

                                'status' => $remoteMachines['status'] ?? null,
                            ])
                            ->values()
                            ->all(),
                    ]
                );

                return;
            }
        }

        if ($this->attempts() >= $this->tries) {
            $this->permanentlyFail(
                'machine_request',
                null,
                'Nao restaram tentativas para solicitar e confirmar a maquina com seguranca.'
            );

            return;
        }

        if (! $this->storeProgress(
            self::PHASE_MACHINE_STARTED,
            $this->machineProgress($remoteLeadId, $machine)
        ) || ! $this->isStillProcessing($remoteLeadId)) {
            return;
        }

        try {
            $action = $leadLovers->moveLeadsToMachine([
                'machineFrom' => 0,
                'machineId' => $machine['machine_id'],
                'sequenceId' => $machine['sequence_id'],
                'level' => $machine['level'],
                'leadIds' => [$remoteLeadId],
            ]);
        } catch (LeadLoversApiException $exception) {
            if ($exception->errorCode === 'LOCAL_RATE_LIMIT') {
                if (! $this->storeProgress(
                    self::PHASE_LEAD_PERSISTED,
                    array_merge(
                        $this->machineProgress($remoteLeadId, $machine),
                        ['source' => 'existing']
                    )
                )) {
                    return;
                }
                $this->retryOrFail(
                    'machine_request',
                    $exception,
                    'A solicitacao de maquina excedeu as tentativas configuradas.'
                );

                return;
            }

            if ($this->isActiveMachineCopy($exception)) {
                if (! $this->storeProgress(
                    self::PHASE_MACHINE_CONFLICT,
                    array_merge(
                        $this->machineProgress($remoteLeadId, $machine),
                        [
                            'status_code' => $exception->statusCode,
                            'error_code' => $exception->errorCode,
                        ]
                    )
                )) {
                    return;
                }
                $machines = $this->remoteMachinesOrNull(
                    $leadLovers,
                    $remoteLeadId,
                    'machine_conflict_confirmation'
                );

                if ($machines === null) {
                    return;
                }

                if ($this->hasExpectedMachine($machines, $machine)) {
                    $this->finishInitialSend($remoteLeadId, $machine);

                    return;
                }

                $this->retryOrFail(
                    'machine_conflict_confirmation',
                    $exception,
                    'O conflito de copia permaneceu sem confirmacao do estado remoto.'
                );

                return;
            }

            if ($exception->isTransient) {
                $this->retryOrFail(
                    'machine_request',
                    $exception,
                    'O resultado da solicitacao de maquina permaneceu incerto.'
                );

                return;
            }

            $this->permanentlyFail(
                'machine_request',
                $exception,
                'A LeadLovers recusou a associacao do lead a maquina.'
            );

            return;
        }

        $actionSummary = [
            'action_id' => $action['actionId'],
            'status' => $action['status'],
            'total' => $action['total'],
        ];

        if (! $this->storeProgress(
            self::PHASE_MACHINE_PENDING,
            array_merge(
                $this->machineProgress($remoteLeadId, $machine),
                ['action' => $actionSummary]
            )
        )) {
            return;
        }

        if (in_array($action['status'], ['failed', 'cancelled'], true)) {
            $this->permanentlyFail(
                'machine_request',
                null,
                'A acao de maquina foi recusada antes da confirmacao.',
                ['action' => $actionSummary]
            );

            return;
        }

        $this->retryOrFail(
            'machine_confirmation',
            null,
            'A associacao do lead a maquina nao foi confirmada a tempo.'
        );
    }

    private function finishInitialSend(
        int $remoteLeadId,
        array $machine
    ): void {
        $pendingUpdate = $this->completeInitialSend($remoteLeadId, $machine);

        if ($pendingUpdate === null) {
            return;
        }

        try {
            $job = new UpdateLeadOnLeadLoversJob(
                leadId: $pendingUpdate['lead_id'],
                syncVersion: $pendingUpdate['sync_version'],
                requestedFields: $pendingUpdate['requested_fields'],
            );
            $job->onQueue('leadlovers')
                ->afterCommit()
                ->delay(now()->addSeconds(max(
                    1,
                    (int) config('services.leadlovers.initial_update_delay_seconds', 60)
                )));
            Bus::dispatch($job);
        } catch (Throwable $exception) {
            Lead::query()
                ->whereKey($pendingUpdate['lead_id'])
                ->where(
                    'leadlovers_update_version',
                    $pendingUpdate['sync_version']
                )
                ->where('leadlovers_update_status', 'pending')
                ->update([
                    'leadlovers_update_status' => 'failed',
                    'leadlovers_update_error' => 'A atualizacao apos o envio inicial nao pode ser colocada na fila.',
                ]);

            Log::warning('Falha ao enfileirar atualizacao apos o envio inicial.', [
                'lead_id' => $pendingUpdate['lead_id'],
                'sync_version' => $pendingUpdate['sync_version'],
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * @return array{lead_id: int, sync_version: int, requested_fields: array<int, string>}|null
     */
    private function completeInitialSend(
        int $remoteLeadId,
        array $machine
    ): ?array {
        return DB::transaction(function () use ($remoteLeadId, $machine): ?array {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lead->leadlovers_status !== 'processing'
                || (int) $lead->leadlovers_lead_id !== $remoteLeadId
            ) {
                return null;
            }

            $requestedFields = in_array(
                $lead->leadlovers_update_status,
                ['waiting_initial_send', 'disabled'],
                true
            )
                && is_array($lead->leadlovers_update_response)
                && is_array(
                    $lead->leadlovers_update_response['requested_fields']
                        ?? null
                )
                    ? $lead->leadlovers_update_response['requested_fields']
                    : [];
            $previousAction = is_array($lead->leadlovers_response)
                && is_array($lead->leadlovers_response['action'] ?? null)
                    ? $lead->leadlovers_response['action']
                    : null;
            $summary = [
                'success' => true,
                'phase' => 'machine_confirmed',
                'lead_id' => $remoteLeadId,
                'machine' => [
                    'machine_id' => $machine['machine_id'],
                    'sequence_id' => $machine['sequence_id'],
                    'level' => $machine['level'],
                ],
            ];

            if ($previousAction !== null) {
                $summary['action'] = $previousAction;
            }

            $lead->forceFill([
                'leadlovers_status' => 'sent',
                'leadlovers_response' => $summary,
                'sent_to_leadlovers_at' => now(),
            ]);

            if ($requestedFields === []) {
                $lead->save();

                return null;
            }

            $syncVersion = (int) $lead->leadlovers_update_version + 1;

            $lead->forceFill([
                'leadlovers_update_status' => 'pending',
                'leadlovers_update_version' => $syncVersion,
                'leadlovers_update_error' => null,
                'leadlovers_update_response' => [
                    'requested_fields' => $requestedFields,
                ],
                'leadlovers_update_requested_at' => now(),
            ])->save();

            return [
                'lead_id' => (int) $lead->id,
                'sync_version' => $syncVersion,
                'requested_fields' => $requestedFields,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function creationPayload(Lead $lead, int $mainTagId): array
    {
        return [
            'staticFields' => [
                'email' => $this->nullableString($lead->email),
                'name' => $this->nullableString($lead->nome),
                'phone' => $this->nullableString($lead->tel),
                'city' => $this->nullableString($lead->endereco?->cidade_imovel),
                'state' => $this->nullableString($lead->endereco?->estado),
                'company' => $this->nullableString(
                    $lead->company?->name
                        ?? $lead->imobiliariaInformada?->nome_imobiliaria_informada
                        ?? $lead->imobiliaria
                ),
            ],
            'tags' => [$mainTagId],
            'dynamicFields' => $this->dynamicFieldsForLead($lead),
        ];
    }

    /**
     * @return array<int, array{id: int, value: string}>
     */
    private function dynamicFieldsForLead(Lead $lead): array
    {
        $values = [
            'cpf' => $lead->cpf,
            'estado_civil' => $lead->estado_civil,
            'conjuge_cpf' => $lead->conjuge?->cpf,
            'valor_aluguel' => $lead->despesas?->valor_aluguel,
            'valor_agua' => $lead->despesas?->valor_agua,
            'valor_luz' => $lead->despesas?->valor_luz,
            'valor_gas' => $lead->despesas?->valor_gas,
            'valor_condominio' => $lead->despesas?->valor_condominio,
            'valor_iptu' => $lead->despesas?->valor_iptu,
            'outras_despesas' => $lead->despesas?->outras_despesas,
        ];
        $fields = [];

        foreach (config('services.leadlovers.dynamic_fields', []) as $name => $fieldId) {
            $normalizedId = $this->positiveInteger($fieldId);
            $value = $values[$name] ?? null;

            if ($normalizedId === null || $value === null) {
                continue;
            }

            $fields[] = [
                'id' => $normalizedId,
                'value' => trim((string) $value),
            ];
        }

        return $fields;
    }

    /**
     * @return array{machine_id: int, sequence_id: int, level: int}|null
     */
    private function machineConfigurationForLead(Lead $lead): ?array
    {
        $machineId = $this->positiveInteger(
            config('services.leadlovers.machine')
        );
        $sequenceId = $this->sequenceCodeForLead($lead);
        $level = $this->configuredInteger(
            config('services.leadlovers.step', 1)
        );

        if ($machineId === null || $sequenceId === null || $level === null) {
            return null;
        }

        return [
            'machine_id' => $machineId,
            'sequence_id' => $sequenceId,
            'level' => $level,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $machines
     * @param  array{machine_id: int, sequence_id: int, level: int}  $expected
     */
    private function hasExpectedMachine(array $machines, array $expected): bool
    {
        foreach ($machines as $machine) {
            if (
                ($machine['id'] ?? null) === $expected['machine_id']
                && ($machine['level'] ?? null) === $expected['level']
                && is_array($machine['sequence'] ?? null)
                && ($machine['sequence']['id'] ?? null) === $expected['sequence_id']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function remoteMachinesOrNull(
        LeadLoversApiClient $leadLovers,
        int $remoteLeadId,
        string $operation
    ): ?array {
        try {
            return $leadLovers->listLeadMachines($remoteLeadId);
        } catch (LeadLoversApiException $exception) {
            if ($exception->isTransient) {
                $this->retryOrFail(
                    $operation,
                    $exception,
                    'A consulta da maquina remota excedeu as tentativas configuradas.'
                );

                return null;
            }

            $this->permanentlyFail(
                $operation,
                $exception,
                'Nao foi possivel consultar as maquinas do lead remoto.'
            );

            return null;
        }
    }

    /**
     * @return array{outcome: string, lead_id?: int}
     */
    private function uniqueExactSearchMatch(array $result, string $email): array
    {
        $records = $result['records'] ?? null;
        $pagination = $result['pagination'] ?? null;

        if (
            ! is_array($records)
            || ! is_array($pagination)
            || ($result['total'] ?? null) !== count($records)
            || ($result['total'] ?? null) !== 1
            || count($records) !== 1
            || ($pagination['current'] ?? null) !== 1
            || ($pagination['next'] ?? null) !== null
            || ($pagination['pages'] ?? null) !== 1
        ) {
            return [
                'outcome' => ($result['total'] ?? null) === 0
                    ? 'missing'
                    : 'ambiguous',
            ];
        }

        $record = $records[0];
        $recordEmail = is_array($record) && is_string($record['email'] ?? null)
            ? trim($record['email'])
            : null;
        $remoteLeadId = is_array($record)
            ? $this->positiveInteger($record['leadId'] ?? null)
            : null;

        if ($recordEmail !== $email || $remoteLeadId === null) {
            return ['outcome' => 'ambiguous'];
        }

        return [
            'outcome' => 'matched',
            'lead_id' => $remoteLeadId,
        ];
    }

    private function persistRemoteLeadId(int $remoteLeadId, string $source): bool
    {
        return DB::transaction(function () use ($remoteLeadId, $source): bool {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->where('leadlovers_status', 'processing')
                ->lockForUpdate()
                ->first();

            if (! $lead) {
                return false;
            }

            $currentId = $this->positiveInteger($lead->leadlovers_lead_id);
            $encryptedEmail = is_array($lead->leadlovers_response)
                && is_string(
                    $lead->leadlovers_response['creation_email_encrypted']
                        ?? null
                )
                    ? $lead->leadlovers_response['creation_email_encrypted']
                    : null;

            if ($currentId !== null && $currentId !== $remoteLeadId) {
                Log::warning('Envio inicial nao sobrescreveu um ID remoto mais novo.', [
                    'lead_id' => $lead->id,
                    'current_remote_id' => $currentId,
                    'received_remote_id' => $remoteLeadId,
                ]);

                return false;
            }

            $progress = [
                'success' => false,
                'phase' => self::PHASE_LEAD_PERSISTED,
                'lead_id' => $remoteLeadId,
                'source' => $source,
            ];

            if ($encryptedEmail !== null) {
                $progress['creation_email_encrypted'] = $encryptedEmail;
            }

            $lead->forceFill([
                'leadlovers_lead_id' => $remoteLeadId,
                'leadlovers_response' => $progress,
            ])->save();

            return true;
        });
    }

    private function storeProgress(string $phase, array $details = []): bool
    {
        return DB::transaction(function () use ($phase, $details): bool {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->where('leadlovers_status', 'processing')
                ->lockForUpdate()
                ->first();

            if (! $lead) {
                return false;
            }

            $encryptedEmail = is_array($lead->leadlovers_response)
                && is_string(
                    $lead->leadlovers_response['creation_email_encrypted']
                        ?? null
                )
                    ? $lead->leadlovers_response['creation_email_encrypted']
                    : null;
            $action = is_array($lead->leadlovers_response)
                && is_array($lead->leadlovers_response['action'] ?? null)
                    ? $lead->leadlovers_response['action']
                    : null;
            $progress = array_merge([
                'success' => false,
                'phase' => $phase,
            ], $details);

            if (
                $encryptedEmail !== null
                && ! array_key_exists('creation_email_encrypted', $progress)
            ) {
                $progress['creation_email_encrypted'] = $encryptedEmail;
            }

            if ($action !== null && ! array_key_exists('action', $progress)) {
                $progress['action'] = $action;
            }

            $lead->forceFill([
                'leadlovers_response' => $progress,
            ])->save();

            return true;
        });
    }

    private function isStillProcessing(?int $remoteLeadId = null): bool
    {
        $query = Lead::query()
            ->whereKey($this->leadId)
            ->where('leadlovers_status', 'processing');

        if ($remoteLeadId === null) {
            $query->whereNull('leadlovers_lead_id');
        } else {
            $query->where('leadlovers_lead_id', $remoteLeadId);
        }

        return $query->exists();
    }

    private function retryOrFail(
        string $operation,
        ?LeadLoversApiException $exception,
        string $exhaustedMessage,
        array $diagnosticContext = []
    ): void {
        if ($this->attempts() >= $this->tries) {
            $this->permanentlyFail(
                $operation,
                $exception,
                $exhaustedMessage
            );

            return;
        }

        $delay = $exception?->retryAfterSeconds
            ?? $this->confirmationDelay();

        Log::notice('Envio inicial devolvido a fila para conciliacao.',
            array_merge(
                $diagnosticContext,
                [
                    'lead_id' => $this->leadId,
                    'operation' => $operation,
                    'attempt' => $this->attempts(),
                    'retry_after' => $delay,
                    'status_code' => $exception?->statusCode,
                    'error_code' => $exception?->errorCode,
                ]
            ) 
        );

        $this->release($delay);
    }

    private function permanentlyFail(
        string $operation,
        ?LeadLoversApiException $exception,
        string $updateError,
        array $extra = []
    ): void {
        $this->failInitialSend(
            $this->leadId,
            'failed',
            array_merge(
                $this->failureSummary($operation, $exception),
                $extra
            ),
            $updateError
        );

        Log::warning('Envio inicial do lead falhou de forma segura.', [
            'lead_id' => $this->leadId,
            'operation' => $operation,
            'attempt' => $this->attempts(),
            'status_code' => $exception?->statusCode,
            'error_code' => $exception?->errorCode,
        ]);

        $this->fail(new RuntimeException('Falha definitiva no envio inicial para a LeadLovers.'));
    }

    private function failureSummary(
        string $operation,
        ?LeadLoversApiException $exception
    ): array {
        $summary = [
            'success' => false,
            'phase' => 'failed',
            'operation' => $operation,
            'status_code' => $exception?->statusCode,
        ];

        if ($exception?->errorCode !== null) {
            $summary['error_code'] = $exception->errorCode;
        }

        return $summary;
    }

    public function failed(?Throwable $exception): void
    {
        $this->failInitialSend(
            $this->leadId,
            'failed',
            $this->failureSummary('queue', null),
            'O envio inicial falhou apos as tentativas configuradas.'
        );

        Log::warning('Envio inicial do lead esgotou as tentativas.', [
            'lead_id' => $this->leadId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    private function claimInitialSend(): bool
    {
        $allowedStatuses = $this->attempts() > 1
            ? ['pending', 'processing']
            : ['pending', 'tag_failed', 'sequence_failed', 'disabled'];

        return Lead::query()
            ->whereKey($this->leadId)
            ->whereIn('leadlovers_status', $allowedStatuses)
            ->update([
                'leadlovers_status' => 'processing',
            ]) === 1;
    }

    private function disableInitialSend(): void
    {
        DB::transaction(function (): void {
            $lead = Lead::query()
                ->whereKey($this->leadId)
                ->whereNotIn('leadlovers_status', ['sent', 'send'])
                ->lockForUpdate()
                ->first();

            if (! $lead) {
                return;
            }

            $wasProcessing = $lead->leadlovers_status === 'processing';
            $attributes = [
                'leadlovers_status' => $wasProcessing ? 'failed' : 'disabled',
            ];

            if ($wasProcessing) {
                $failure = $this->failureSummary(
                    'integration_disabled',
                    null
                );

                if (
                    is_array($lead->leadlovers_response)
                    && is_array($lead->leadlovers_response['action'] ?? null)
                ) {
                    $failure['action'] = $lead->leadlovers_response['action'];
                }

                $attributes['leadlovers_response'] = $failure;
            }

            if ($lead->leadlovers_update_status === 'waiting_initial_send') {
                $attributes['leadlovers_update_status'] = $wasProcessing
                    ? 'failed'
                    : 'disabled';
                $attributes['leadlovers_update_error'] = $wasProcessing
                    ? 'O envio inicial foi interrompido em estado ambiguo e precisa ser conciliado.'
                    : 'A integracao com a LeadLovers esta desativada.';
            }

            $lead->forceFill($attributes)->save();
        });
    }

    private function failInitialSend(
        int $leadId,
        string $status,
        array $response,
        string $updateError
    ): void {
        DB::transaction(function () use (
            $leadId,
            $status,
            $response,
            $updateError
        ): void {
            $lead = Lead::query()
                ->whereKey($leadId)
                ->where('leadlovers_status', 'processing')
                ->lockForUpdate()
                ->first();

            if (! $lead) {
                return;
            }

            $attributes = [
                'leadlovers_status' => $status,
                'leadlovers_response' => $response,
            ];

            if (
                is_array($lead->leadlovers_response)
                && is_array($lead->leadlovers_response['action'] ?? null)
                && ! array_key_exists('action', $response)
            ) {
                $attributes['leadlovers_response']['action'] =
                    $lead->leadlovers_response['action'];
            }

            if ($lead->leadlovers_update_status === 'waiting_initial_send') {
                $attributes['leadlovers_update_status'] = 'failed';
                $attributes['leadlovers_update_error'] = $updateError;
            }

            $lead->forceFill($attributes)->save();
        });
    }

    private function mainTagIdForLead(Lead $lead): ?int
    {
        if ($lead->tipo_solicitante === 'imobiliaria_cadastrada') {
            return $this->companyTagId($lead);
        }

        $tagKey = match ($lead->tipo_solicitante) {
            'locatario' => 'locatario',
            'imobiliaria_nao_cadastrada' => 'imobiliaria_morna',
            'locador' => 'diretoprop',
            default => null,
        };

        if ($tagKey === null) {
            return null;
        }

        return $this->positiveInteger(
            LeadLoversTag::query()
                ->where('key', $tagKey)
                ->where('active', true)
                ->value('leadlovers_tag_id')
        );
    }

    private function companyTagId(Lead $lead): ?int
    {
        if (! $lead->company) {
            return null;
        }

        $companyTagId = $this->positiveInteger(
            $lead->company->leadlovers_tag_id
        );

        if ($companyTagId !== null) {
            return $companyTagId;
        }

        return $this->positiveInteger(
            LeadLoversTag::query()
                ->where('title', $lead->company->name)
                ->where('active', true)
                ->value('leadlovers_tag_id')
        );
    }

    private function sequenceCodeForLead(Lead $lead): ?int
    {
        return $this->positiveInteger(
            $lead->tipo_solicitante === 'locatario'
                ? config('services.leadlovers.sequence_2')
                : config('services.leadlovers.sequence_1')
        );
    }

    private function loadLead(): Lead
    {
        return Lead::query()
            ->with([
                'company',
                'endereco',
                'imobiliariaInformada',
                'conjuge',
                'despesas',
            ])
            ->findOrFail($this->leadId);
    }

    private function currentPhase(Lead $lead): ?string
    {
        $phase = is_array($lead->leadlovers_response)
            ? $lead->leadlovers_response['phase'] ?? null
            : null;

        return is_string($phase) ? $phase : null;
    }

    private function currentReconciliationReason(Lead $lead): ?string
    {
        $reason = is_array($lead->leadlovers_response)
            ? $lead->leadlovers_response['reconciliation_reason'] ?? null
            : null;

        return is_string($reason) ? $reason : null;
    }

    private function encryptCreationEmail(string $email): ?string
    {
        try {
            return Crypt::encryptString($email);
        } catch (Throwable) {
            return null;
        }
    }

    private function storedCreationEmail(Lead $lead): ?string
    {
        $encrypted = is_array($lead->leadlovers_response)
            ? $lead->leadlovers_response['creation_email_encrypted'] ?? null
            : null;

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $email = trim(Crypt::decryptString($encrypted));
        } catch (Throwable) {
            return null;
        }

        return $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                ? $email
                : null;
    }

    private function isEmailExists(LeadLoversApiException $exception): bool
    {
        return $exception->statusCode === 400
            && mb_strtoupper((string) $exception->errorCode) === 'EMAIL_EXISTS';
    }

    private function isActiveMachineCopy(
        LeadLoversApiException $exception
    ): bool {
        return $exception->statusCode === 409
            && mb_strtoupper((string) $exception->errorCode)
                === 'ACTIVE_COPY_BETWEEN_MACHINES';
    }

    private function confirmationDelay(): int
    {
        $base = max(
            1,
            (int) config(
                'services.leadlovers.machine_confirmation_delay_seconds',
                15
            )
        );
        $maximum = max(
            $base,
            (int) config(
                'services.leadlovers.rate_limit_max_retry_seconds',
                900
            )
        );
        $multiplier = 2 ** min(max(0, $this->attempts() - 1), 5);

        return min($maximum, $base * $multiplier);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[1-9]\d*\z/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) && $integer > 0 ? $integer : null;
    }

    private function configuredInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/\A-?\d+\z/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) ? $integer : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : trim((string) $value);
    }

    /**
     * @param  array{machine_id: int, sequence_id: int, level: int}  $machine
     */
    private function machineProgress(int $remoteLeadId, array $machine): array
    {
        return [
            'lead_id' => $remoteLeadId,
            'machine' => [
                'machine_id' => $machine['machine_id'],
                'sequence_id' => $machine['sequence_id'],
                'level' => $machine['level'],
            ],
        ];
    }
}
