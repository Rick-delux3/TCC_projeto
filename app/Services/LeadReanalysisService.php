<?php

namespace App\Services;

use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use DomainException;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use App\Events\DashboardActivityChanged;

class LeadReanalysisService
{
    private const LEADLOVERS_UPDATE_FIELDS = [
        'name',
        'phone',
        'city',
        'state',
        'company',
        'cpf',
        'estado_civil',
        'conjuge_cpf',
        'valor_aluguel',
        'valor_agua',
        'valor_luz',
        'valor_gas',
        'valor_condominio',
        'valor_iptu',
        'outras_despesas',
    ];

    public function updateLeadDataAndMaybeUnlock(Lead $lead, array $data): array
    {
        $analysisEnabled = (bool) config(
            'features.insurance_analysis.enabled',
            false
        );
        $result = DB::transaction(function () use (
            $lead,
            $data,
            $analysisEnabled,
        ): array {
            $lead = Lead::query()
                ->with(['endereco', 'despesas', 'conjuge'])
                ->whereKey($lead->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $submittedValue = static fn (string $field, mixed $current): mixed => array_key_exists($field, $data) ? $data[$field] : $current;
            $normalizeNullableString = static function (mixed $value): ?string {
                if ($value === null) {
                    return null;
                }

                $value = trim((string) $value);

                return $value === '' ? null : $value;
            };
            $normalizeNullableDecimal = static function (mixed $value): ?string {
                if ($value === null) {
                    return null;
                }

                if (is_string($value)) {
                    $value = trim($value);

                    if ($value === '') {
                        return null;
                    }
                }

                return number_format((float) $value, 2, '.', '');
            };
            $submittedStringValue = static function (
                string $field,
                mixed $current
            ) use ($data, $normalizeNullableString): mixed {
                return array_key_exists($field, $data)
                    ? $normalizeNullableString($data[$field])
                    : $current;
            };
            $submittedStringChanged = static function (
                string $field,
                mixed $before,
                mixed $after
            ) use ($data, $normalizeNullableString): bool {
                return array_key_exists($field, $data)
                    && $normalizeNullableString($before)
                        !== $normalizeNullableString($after);
            };
            $submittedDecimalChanged = static function (
                string $field,
                mixed $before,
                mixed $after
            ) use ($data, $normalizeNullableDecimal): bool {
                return array_key_exists($field, $data)
                    && $normalizeNullableDecimal($before)
                        !== $normalizeNullableDecimal($after);
            };
            $hasMeaningfulValue = static function (array $values): bool {
                foreach ($values as $value) {
                    if (filled($value)) {
                        return true;
                    }
                }

                return false;
            };
            $originalRemoteValues = [
                'name' => $lead->nome,
                'phone' => $lead->tel,
                'cpf' => $lead->cpf,
                'estado_civil' => $lead->estado_civil,
                'city' => $lead->endereco?->cidade_imovel,
                'state' => $lead->endereco?->estado,
                'conjuge_cpf' => $lead->conjuge?->cpf,
                'valor_aluguel' => $lead->despesas?->valor_aluguel,
                'valor_agua' => $lead->despesas?->valor_agua,
                'valor_luz' => $lead->despesas?->valor_luz,
                'valor_gas' => $lead->despesas?->valor_gas,
                'valor_condominio' => $lead->despesas?->valor_condominio,
                'valor_iptu' => $lead->despesas?->valor_iptu,
                'outras_despesas' => $lead->despesas?->outras_despesas,
            ];
            $hadEndereco = $lead->endereco !== null;
            $hadDespesas = $lead->despesas !== null;
            $hadConjuge = $lead->conjuge !== null;

            $lead->fill([
                'nome' => $data['nome'] ?? $lead->nome,
                'tel' => $submittedStringValue('tel', $lead->tel),
                'cpf' => $submittedStringValue('cpf', $lead->cpf),
                'tipo_solicitante' => $submittedStringValue('tipo_solicitante', $lead->tipo_solicitante),
                'estado_civil' => $submittedStringValue('estado_civil', $lead->estado_civil),
            ]);

            $endereco = $lead->endereco ?: $lead->endereco()->make();
            $endereco->fill([
                'cep' => $submittedStringValue('cep', $endereco->cep),
                'estado' => $submittedStringValue('estado', $endereco->estado),
                'cidade_imovel' => $submittedStringValue('cidade_imovel', $endereco->cidade_imovel),
                'bairro' => $submittedStringValue('bairro', $endereco->bairro),
                'logradouro' => $submittedStringValue('logradouro', $endereco->logradouro),
                'numero' => $submittedStringValue('numero', $endereco->numero),
                'complemento' => $submittedStringValue('complemento', $endereco->complemento),
            ]);

            $despesas = $lead->despesas ?: $lead->despesas()->make();
            $numericValue = static function (
                string $field,
                mixed $current
            ) use ($submittedValue, $normalizeNullableDecimal): ?float {
                $value = $submittedValue($field, $current);
                $value = $normalizeNullableDecimal($value);

                return $value === null
                    ? null
                    : (float) $value;
            };
            $valorAluguel = $numericValue('valor_aluguel', $despesas->valor_aluguel);
            $valorAgua = $numericValue('valor_agua', $despesas->valor_agua);
            $valorLuz = $numericValue('valor_luz', $despesas->valor_luz);
            $valorGas = $numericValue('valor_gas', $despesas->valor_gas);
            $valorCondominio = $numericValue('valor_condominio', $despesas->valor_condominio);
            $valorIptu = $numericValue('valor_iptu', $despesas->valor_iptu);
            $outrasDespesas = $numericValue('outras_despesas', $despesas->outras_despesas);

            $despesas->fill([
                'valor_aluguel' => $valorAluguel,
                'valor_agua' => $valorAgua,
                'valor_luz' => $valorLuz,
                'valor_gas' => $valorGas,
                'valor_condominio' => $valorCondominio,
                'valor_iptu' => $valorIptu,
                'outras_despesas' => $outrasDespesas,
                'valor_total_encargos' => (float) ($valorAluguel ?? 0)
                    + (float) ($valorAgua ?? 0)
                    + (float) ($valorLuz ?? 0)
                    + (float) ($valorGas ?? 0)
                    + (float) ($valorCondominio ?? 0)
                    + (float) ($valorIptu ?? 0)
                    + (float) ($outrasDespesas ?? 0),
            ]);

            $conjuge = $lead->conjuge ?: $lead->conjuge()->make();
            $conjuge->fill([
                'nome' => $submittedStringValue('conjuge_nome', $conjuge->nome),
                'cpf' => $submittedStringValue('conjuge_cpf', $conjuge->cpf),
            ]);

            $leadChanged = $lead->isDirty();
            $enderecoChanged = $hadEndereco
                ? $endereco->isDirty()
                : $hasMeaningfulValue($endereco->only([
                    'cep',
                    'estado',
                    'cidade_imovel',
                    'bairro',
                    'logradouro',
                    'numero',
                    'complemento',
                ]));
            $despesasChanged = $hadDespesas
                ? $despesas->isDirty()
                : $hasMeaningfulValue($despesas->only([
                    'valor_aluguel',
                    'valor_agua',
                    'valor_luz',
                    'valor_gas',
                    'valor_condominio',
                    'valor_iptu',
                    'outras_despesas',
                ]));
            $conjugeChanged = $hadConjuge
                ? $conjuge->isDirty()
                : $hasMeaningfulValue($conjuge->only(['nome', 'cpf']));
            $requestedLeadLoversFields = $this->normalizeLeadLoversUpdateFields([
                $submittedStringChanged(
                    'nome',
                    $originalRemoteValues['name'],
                    $lead->nome
                )
                    ? 'name' : null,
                $submittedStringChanged(
                    'tel',
                    $originalRemoteValues['phone'],
                    $lead->tel
                )
                    ? 'phone' : null,
                $submittedStringChanged(
                    'cpf',
                    $originalRemoteValues['cpf'],
                    $lead->cpf
                )
                    ? 'cpf' : null,
                $submittedStringChanged(
                    'estado_civil',
                    $originalRemoteValues['estado_civil'],
                    $lead->estado_civil
                )
                    ? 'estado_civil' : null,
                $submittedStringChanged(
                    'cidade_imovel',
                    $originalRemoteValues['city'],
                    $endereco->cidade_imovel
                )
                    ? 'city' : null,
                $submittedStringChanged(
                    'estado',
                    $originalRemoteValues['state'],
                    $endereco->estado
                )
                    ? 'state' : null,
                $submittedDecimalChanged(
                    'valor_aluguel',
                    $originalRemoteValues['valor_aluguel'],
                    $valorAluguel
                )
                    ? 'valor_aluguel' : null,
                $submittedDecimalChanged(
                    'valor_agua',
                    $originalRemoteValues['valor_agua'],
                    $valorAgua
                )
                    ? 'valor_agua' : null,
                $submittedDecimalChanged(
                    'valor_luz',
                    $originalRemoteValues['valor_luz'],
                    $valorLuz
                )
                    ? 'valor_luz' : null,
                $submittedDecimalChanged(
                    'valor_gas',
                    $originalRemoteValues['valor_gas'],
                    $valorGas
                )
                    ? 'valor_gas' : null,
                $submittedDecimalChanged(
                    'valor_condominio',
                    $originalRemoteValues['valor_condominio'],
                    $valorCondominio
                )
                    ? 'valor_condominio' : null,
                $submittedDecimalChanged(
                    'valor_iptu',
                    $originalRemoteValues['valor_iptu'],
                    $valorIptu
                )
                    ? 'valor_iptu' : null,
                $submittedDecimalChanged(
                    'outras_despesas',
                    $originalRemoteValues['outras_despesas'],
                    $outrasDespesas
                )
                    ? 'outras_despesas' : null,
                $submittedStringChanged(
                    'conjuge_cpf',
                    $originalRemoteValues['conjuge_cpf'],
                    $conjuge->cpf
                )
                    ? 'conjuge_cpf' : null,
            ]);

            if (! $leadChanged && ! $enderecoChanged && ! $despesasChanged && ! $conjugeChanged) {
                return [
                    'changed' => false,
                    'unlocked' => false,
                    'sync_status' => 'idle',
                    'dispatch' => null,
                ];
            }

            if ($leadChanged) {
                $lead->save();
            }

            if ($enderecoChanged) {
                $lead->endereco()->save($endereco);
            }

            if ($despesasChanged) {
                $lead->despesas()->save($despesas);
            }

            if ($conjugeChanged) {
                $lead->conjuge()->save($conjuge);
            }

            $lead->refresh();

            $integrationEnabled = (bool) config(
                'services.leadlovers.enabled',
                false
            );
            $wasSentToLeadLovers = in_array(
                $lead->leadlovers_status,
                ['sent', 'send'],
                true
            )
                && filled($lead->sent_to_leadlovers_at);

            if ($wasSentToLeadLovers && $lead->leadlovers_status === 'send') {
                $lead->forceFill([
                    'leadlovers_status' => 'sent',
                ])->saveQuietly();
            }
            $initialSendCanRetry = in_array($lead->leadlovers_status, [
                'tag_failed',
                'sequence_failed',
                'disabled',
            ], true);
            $initialSendNeedsReconciliation = $lead->leadlovers_status
                === 'failed';
            $syncStatus = 'idle';
            $dispatch = null;

            if ($requestedLeadLoversFields === []) {
                // A alteracao foi apenas local; nao existe dado remoto a enviar.
                $syncStatus = 'idle';
            } elseif (! $integrationEnabled) {
                $syncStatus = 'disabled';
                $requestedLeadLoversFields = $this->normalizeLeadLoversUpdateFields([
                    ...$this->pendingLeadLoversUpdateFields($lead),
                    ...$requestedLeadLoversFields,
                ]);

                $lead->forceFill([
                    'leadlovers_update_status' => $syncStatus,
                    'leadlovers_update_error' => 'Integração com a LeadLovers desativada.',
                    'leadlovers_update_response' => [
                        'requested_fields' => $requestedLeadLoversFields,
                    ],
                ])->saveQuietly();
            } elseif ($initialSendNeedsReconciliation) {
                $syncStatus = 'failed';
                $requestedLeadLoversFields = $this->normalizeLeadLoversUpdateFields([
                    ...$this->pendingLeadLoversUpdateFields($lead),
                    ...$requestedLeadLoversFields,
                ]);

                $lead->forceFill([
                    'leadlovers_update_status' => $syncStatus,
                    'leadlovers_update_error' => 'O envio inicial falhou e precisa ser conciliado antes de uma nova tentativa.',
                    'leadlovers_update_response' => [
                        'requested_fields' => $requestedLeadLoversFields,
                    ],
                ])->saveQuietly();
            } elseif (! $wasSentToLeadLovers) {
                $syncStatus = 'waiting_initial_send';
                $requestedLeadLoversFields = $this->normalizeLeadLoversUpdateFields([
                    ...$this->pendingLeadLoversUpdateFields($lead),
                    ...$requestedLeadLoversFields,
                ]);

                $lead->forceFill([
                    'leadlovers_update_status' => $syncStatus,
                    'leadlovers_update_error' => null,
                    'leadlovers_update_response' => [
                        'requested_fields' => $requestedLeadLoversFields,
                    ],
                ])->saveQuietly();

                if ($initialSendCanRetry) {
                    $lead->forceFill([
                        'leadlovers_status' => 'pending',
                    ])->saveQuietly();

                    $dispatch = [
                        'job_type' => 'initial',
                        'lead_id' => (int) $lead->id,
                    ];
                }
            } else {
                $syncStatus = 'pending';
                $syncVersion = (int) $lead->leadlovers_update_version + 1;
                $requestedLeadLoversFields = $this->normalizeLeadLoversUpdateFields([
                    ...$this->pendingLeadLoversUpdateFields($lead),
                    ...$requestedLeadLoversFields,
                ]);

                $lead->forceFill([
                    'leadlovers_update_status' => $syncStatus,
                    'leadlovers_update_version' => $syncVersion,
                    'leadlovers_update_error' => null,
                    'leadlovers_update_response' => [
                        'requested_fields' => $requestedLeadLoversFields,
                    ],
                    'leadlovers_update_requested_at' => now(),
                ])->saveQuietly();

                $dispatch = [
                    'job_type' => 'update',
                    'lead_id' => (int) $lead->id,
                    'sync_version' => $syncVersion,
                    'requested_fields' => $requestedLeadLoversFields,
                    'not_before' => $this->leadLoversUpdateNotBefore($lead),
                ];
            }

            $unlocked = false;

            if (
                $analysisEnabled
                && $lead->hasFinalInsuranceResultForReanalysis()
            ) {
                $lead->forceFill([
                    'reanalysis_unlocked_at' => now(),
                ])->saveQuietly();

                $unlocked = true;
            }

            return [
                'changed' => true,
                'unlocked' => $unlocked,
                'sync_status' => $syncStatus,
                'dispatch' => $dispatch,
            ];
        });

        if ($result['changed'] === true) {

            $freshLead = Lead::query()
                ->select(['id', 'company_id'])
                ->findOrFail($lead->getKey());

            $freshLeadCompany = $freshLead->company_id !== null
                    ? (int) $freshLead->company_id
                    : null;

            $freshLeadId = (int) $freshLead->id;


            DashboardActivityChanged::dispatch(
                'lead',
                $freshLeadId,
                $freshLeadCompany,
                'lead.updated',
            );
        }

        
        if (! $result['changed']) {
            return [
                'changed' => false,
                'unlocked' => false,
                'message' => 'Altere pelo menos um dado do lead antes de salvar.',
            ];
        }

        if ($result['dispatch'] !== null) {
            $dispatch = $result['dispatch'];

            try {
                if ($dispatch['job_type'] === 'initial') {
                    $job = new SendLeadToLeadLoversJob(
                        leadId: $dispatch['lead_id']
                    );
                    $job->onQueue('leadlovers')->afterCommit();
                } else {
                    $job = new UpdateLeadOnLeadLoversJob(
                        leadId: $dispatch['lead_id'],
                        syncVersion: $dispatch['sync_version'],
                        requestedFields: $dispatch['requested_fields'],
                    );
                    $job->onQueue('leadlovers')->afterCommit();

                    if ($dispatch['not_before'] !== null) {
                        $job->delay($dispatch['not_before']);
                    }
                }

                Bus::dispatch($job);
            } catch (Throwable $exception) {
                $query = Lead::query()->whereKey($dispatch['lead_id']);

                if ($dispatch['job_type'] === 'initial') {
                    $query->where('leadlovers_status', 'pending');
                } else {
                    $query
                        ->where(
                            'leadlovers_update_version',
                            $dispatch['sync_version']
                        )
                        ->where('leadlovers_update_status', 'pending');
                }

                $attributes = [
                    'leadlovers_update_status' => 'failed',
                    'leadlovers_update_error' => 'A sincronização não pôde ser colocada na fila.',
                ];

                if ($dispatch['job_type'] === 'initial') {
                    $attributes['leadlovers_status'] = 'failed';
                }

                $updated = $query->update($attributes);

                if ($updated === 1) {
                    $result['sync_status'] = 'failed';
                }

                Log::warning('Falha ao enfileirar atualização do lead na LeadLovers.', [
                    'lead_id' => $dispatch['lead_id'],
                    'job_type' => $dispatch['job_type'],
                    'sync_version' => $dispatch['sync_version'] ?? null,
                    'exception' => $exception::class,
                ]);
            }
        }

        $message = match ($result['sync_status']) {
            'pending' => 'Dados salvos no sistema. A sincronização com a LeadLovers foi colocada na fila.',
            'waiting_initial_send' => 'Dados salvos no sistema. A atualização na LeadLovers aguarda o envio inicial do lead.',
            'disabled' => 'Dados salvos no sistema. A integração com a LeadLovers está desativada.',
            'failed' => 'Dados salvos no sistema, mas a sincronização com a LeadLovers não pôde ser enfileirada.',
            default => 'Dados salvos no sistema.',
        };

        

        return [
            'changed' => true,
            'unlocked' => $result['unlocked'],
            'message' => $message,
        ];
    }

    private function normalizeLeadLoversUpdateFields(array $fields): array
    {
        $requested = array_fill_keys(
            array_values(array_filter($fields, 'is_string')),
            true
        );

        return array_values(array_filter(
            self::LEADLOVERS_UPDATE_FIELDS,
            static fn (string $field): bool => isset($requested[$field])
        ));
    }

    private function pendingLeadLoversUpdateFields(Lead $lead): array
    {
        if (! in_array($lead->leadlovers_update_status, [
            'pending',
            'processing',
            'failed',
            'waiting_initial_send',
            'disabled',
        ], true)) {
            return [];
        }

        $response = $lead->leadlovers_update_response;

        if (! is_array($response)) {
            return [];
        }

        return $this->normalizeLeadLoversUpdateFields(
            is_array($response['requested_fields'] ?? null)
                ? $response['requested_fields']
                : []
        );
    }

    private function leadLoversUpdateNotBefore(Lead $lead): ?\DateTimeInterface
    {
        if (! $lead->sent_to_leadlovers_at) {
            return null;
        }

        $notBefore = $lead->sent_to_leadlovers_at
            ->copy()
            ->addSeconds(max(
                0,
                (int) config(
                    'services.leadlovers.initial_update_delay_seconds',
                    60
                )
            ));

        return $notBefore->isFuture() ? $notBefore : null;
    }

    public function startGeneralReanalysis(
        Lead $lead,
        string $requestedBy = 'imobiliaria',
        array $options = []
    ): int {
        $this->ensureAnalysisEnabled();

        $lead->loadMissing([
            'endereco',
            'despesas',
            'conjuge',
        ]);

        if (! $lead->canRequestGeneralReanalysis()) {
            throw new DomainException(
                'Para solicitar reanálise geral, o lead precisa estar aprovado ou recusado e ter alterações salvas.'
            );
        }

        $attemptId = (string) Str::uuid();

        $preparedAnalyses = DB::transaction(function () use (
            $lead,
            $requestedBy,
            $options,
            $attemptId
        ) {
            $batch = InsuranceAnalysisBatch::query()
                ->where('lead_id', $lead->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $batch) {
                throw new DomainException(
                    'Nenhum lote de análise encontrado para este lead.'
                );
            }

            $batch->load([
                'analyses.events',
                'analyses.lead.endereco',
                'analyses.lead.despesas',
                'analyses.lead.conjuge',
            ]);

            $eligibleAnalyses = $batch->analyses
                ->filter(
                    fn (InsuranceAnalysis $analysis) => $analysis->canRequestProviderReanalysis()
                )->values();

            if ($eligibleAnalyses->isEmpty()) {
                throw new DomainException(
                    'Nenhuma companhia deste lead está em status permitido para reanálise.'
                );

            }

            $prepared = [];

            foreach ($eligibleAnalyses as $analysis) {
                $providerOptions = $this->optionsForProvider(
                    $analysis,
                    $options
                );

                $this->restartAnalysisAsReanalysis(
                    analysis: $analysis,
                    attemptId: $attemptId,
                    message: 'Reanálise geral solicitada após alteração dos dados do lead.',
                    requestedBy: $requestedBy,
                    options: $providerOptions
                );

                $prepared[] = [
                    'analysis_id' => $analysis->id,
                    'options' => $providerOptions,
                ];
            }

            $lead->forceFill([
                'status' => 'em-andamento',
                'reanalysis_unlocked_at' => null,
            ])->save();

            return [
                'batch_id' => $batch->id,
                'analyses' => $prepared,
            ];

        });

        $jobs = collect($preparedAnalyses['analysis'])
            ->map(fn (array $item) => new RunProviderAnalysisJob(
                analysisId: $item['analysis_id'],
                attemptId: $attemptId,
                isReanalysis: true,
                options: $item['options']
            ))->all();
        $batchId = (int) $preparedAnalyses['batch_id'];
        $leadId = (int) $lead->id;

        Bus::batch($jobs)
            ->name("Reanálise do lead {$leadId}")
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($batchId, $attemptId) {
                CompleteInsuranceAnalysesBatchJob::dispatch(
                    batchId: $batchId,
                    attemptId: $attemptId,
                    isReanalysis: true
                );
            })->dispatch();

        return count($preparedAnalyses['analyses']);
    }

    public function startProviderReanalysis(
        InsuranceAnalysis $analysis,
        string $requestedBy = 'imobiliaria',
        array $options = []
    ): string {
        $this->ensureAnalysisEnabled();
        $analysis->loadMissing([
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'batch.analyses',
            'events',
        ]);

        if (! $analysis->canRequestProviderReanalysis()) {
            throw new DomainException(
                'Esta companhia só pode ser reanalisada se o resultado dela estiver aprovado ou recusado.'
            );
        }

        $providerOptions = $this->optionsForProvider($analysis, $options);

        $attemptId = (string) Str::uuid();

        $this->restartAnalysisAsReanalysis(
            analysis: $analysis,
            attemptId: $attemptId,
            message: 'Reanálise por companhia solicitada após alteração dos dados do lead.',
            requestedBy: $requestedBy,
            options: $providerOptions
        );

        RunProviderAnalysisJob::dispatch(
            analysisId: $analysis->id,
            attemptId: $attemptId,
            isReanalysis: true,
            options: $providerOptions
        );

        return $attemptId;
    }

    private function ensureAnalysisEnabled(): void
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            throw new \LogicException(
                'O sistema de análises está temporariamente desativado.'
            );
        }
    }

    public function restartAnalysisAsReanalysis(
        InsuranceAnalysis $analysis,
        string $message,
        string $attemptId,
        string $requestedBy = 'imobiliaria',
        array $options = []
    ): string {
        DB::transaction(function () use (
            $analysis,
            $attemptId,
            $message,
            $requestedBy,
            $options
        ) {
            $analysis = InsuranceAnalysis::query()
                ->whereKey($analysis->id)
                ->lockForUpdate()
                ->firstOrFail();

            $analysis->loadMissing([
                'batch.analyses',
                'lead.despesas',
                'events',
            ]);

            if (! $analysis->canRequestProviderReanalysis()) {
                throw new DomainException(
                    'Esta análise já está sendo processada ou não permite reanálise.'
                );
            }

            $isToo = $analysis->isTooProvider();

            $previousResponsePayload = $analysis->response_payload ?? [];

            if (is_string($previousResponsePayload)) {
                $previousResponsePayload =
                    json_decode($previousResponsePayload, true) ?: [];
            }

            $tooNumeroProposta =
                data_get($previousResponsePayload, 'numeroProposta')
                ?? data_get($previousResponsePayload, 'numero_proposta')
                ?? $analysis->proposal_id;

            $tooNumeroFicha =
                data_get($previousResponsePayload, 'numeroFicha')
                ?? data_get($previousResponsePayload, 'numero_ficha')
                ?? data_get($previousResponsePayload, 'numeroProposta')
                ?? $analysis->proposal_id;

            if (
                $isToo
                && (
                    blank($tooNumeroProposta)
                    || blank($tooNumeroFicha)
                )
            ) {
                throw new DomainException(
                    'A análise da Too não possui número de proposta/ficha para solicitar reanálise.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Valores atuais do lead
            |--------------------------------------------------------------------------
            */

            $despesas = $analysis->lead?->despesas;

            $rentAmount = (float) ($despesas?->valor_aluguel ?? 0);

            $totalMonthlyAmount = $despesas?->valor_total_encargos;

            if ($totalMonthlyAmount === null || $totalMonthlyAmount === '') {
                $totalMonthlyAmount =
                    $rentAmount
                    + (float) ($despesas?->valor_agua ?? 0)
                    + (float) ($despesas?->valor_luz ?? 0)
                    + (float) ($despesas?->valor_gas ?? 0)
                    + (float) ($despesas?->valor_condominio ?? 0)
                    + (float) ($despesas?->valor_iptu ?? 0)
                    + (float) ($despesas?->outras_despesas ?? 0);
            }

            $totalMonthlyAmount = (float) $totalMonthlyAmount;
            $chargesAmount = max(0, $totalMonthlyAmount - $rentAmount);

            /*
            |--------------------------------------------------------------------------
            | Histórico anterior
            |--------------------------------------------------------------------------
            */

            $analysis->events()->create([
                'event_type' => 'reanalysis_requested',
                'status' => 'pending',
                'message' => $message,
                'payload' => [
                    'attempt_id' => $attemptId,
                    'is_reanalysis' => true,
                    'requested_by' => $requestedBy,
                    'requested_at' => now()->toDateTimeString(),
                    'provider' => $analysis->provider,
                    'reanalysis_options' => $options,

                    'previous_status' => $analysis->status,
                    'previous_result' => $analysis->result,
                    'previous_provider_status' => $analysis->provider_status,
                    'previous_proposal_id' => $analysis->proposal_id,
                    'previous_quote_id' => $analysis->quote_id,
                    'previous_quote_number' => $analysis->quote_number,
                    'previous_premium_amount' => $analysis->premium_amount,
                    'previous_commercial_premium' => $analysis->commercial_premium,
                    'previous_gross_premium' => $analysis->gross_premium,
                    'previous_iof' => $analysis->iof,
                    'previous_insured_amount' => $analysis->insured_amount,

                    'previous_rent_amount' => $analysis->rent_amount,
                    'previous_charges_amount' => $analysis->charges_amount,
                    'previous_total_monthly_amount' => $analysis->total_monthly_amount,

                    'new_rent_amount' => $rentAmount,
                    'new_charges_amount' => $chargesAmount,
                    'new_total_monthly_amount' => $totalMonthlyAmount,
                ],
                'response' => $analysis->response_payload,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Prepara a rodada atual
            |--------------------------------------------------------------------------
            */

            $analysis->forceFill([
                'status' => 'pending',
                'result' => null,
                'provider_status' => null,

                // Too mantém proposta. Outros providers podem criar nova solicitação.
                'proposal_id' => $isToo
                    ? (string) $tooNumeroProposta
                    : null,

                'quote_id' => null,
                'quote_number' => null,
                'product_key' => null,

                'commercial_premium' => null,
                'gross_premium' => null,
                'iof' => null,

                'request_payload' => null,

                // Não carregamos status e resultados antigos para a rodada nova.
                'response_payload' => $isToo
                    ? [
                        'provider' => 'too',
                        'numeroProposta' => (string) $tooNumeroProposta,
                        'numeroFicha' => (string) $tooNumeroFicha,
                        'too_reanalysis_attempt_id' => $attemptId,
                        'too_reanalysis_started_at' => now()->toDateTimeString(),
                        'too_status_check_stopped' => false,
                        'too_manual_sync_available' => false,
                    ]
                    : null,

                'available_plans' => null,
                'available_assistances' => null,

                'premium_amount' => null,
                'insured_amount' => null,

                // Valores atualizados que serão enviados na nova rodada.
                'rent_amount' => $rentAmount,
                'charges_amount' => $chargesAmount,
                'total_monthly_amount' => $totalMonthlyAmount,

                'error_message' => null,
                'requested_at' => null,
                'finished_at' => null,
            ])->save();

            /*
            |--------------------------------------------------------------------------
            | Reabre o lote
            |--------------------------------------------------------------------------
            */

            if ($analysis->batch) {
                $completed = $analysis->batch->analyses()
                    ->whereIn('status', [
                        'quoted',
                        'approved',
                        'rejected',
                        'denied',
                        'refused',
                        'manual_review',
                    ])
                    ->count();

                $failed = $analysis->batch->analyses()
                    ->whereIn('status', ['failed', 'error'])
                    ->count();

                $analysis->batch->forceFill([
                    'status' => 'processing',
                    'completed_providers' => $completed,
                    'failed_providers' => $failed,
                    'finished_at' => null,
                    'started_at' => now(),
                ])->save();
            }

        });

        return $attemptId;
    }

    public function startTechnicalRetry(
        InsuranceAnalysis $analysis,
        string $requestedBy = 'imobiliaria'
    ): string {
        $normalizedStatus = mb_strtolower((string) $analysis->status);

        if (! in_array($normalizedStatus, ['failed', 'error'], true)) {
            throw new DomainException(
                'Essa análise não está em status de falha técnica para reenvio.'
            );
        }

        $attemptId = (string) Str::uuid();

        $analysis->loadMissing([
            'batch.analyses',
            'lead.despesas',
            'events',
        ]);

        DB::transaction(function () use ($analysis, $attemptId, $requestedBy) {
            $analysis->events()->create([
                'event_type' => 'technical_retry_requested',
                'status' => 'pending',
                'message' => $requestedBy === 'admin'
                    ? 'Reenvio técnico da análise solicitado pelo admin/corretor.'
                    : 'Reenvio técnico da análise solicitado pela imobiliária.',
                'payload' => [
                    'attempt_id' => $attemptId,
                    'is_reanalysis' => false,
                    'is_technical_retry' => true,
                    'requested_by' => $requestedBy,
                    'requested_at' => now()->toDateTimeString(),
                    'provider' => $analysis->provider,

                    'previous_status' => $analysis->status,
                    'previous_result' => $analysis->result,
                    'previous_provider_status' => $analysis->provider_status,
                    'previous_proposal_id' => $analysis->proposal_id,
                    'previous_quote_id' => $analysis->quote_id,
                    'previous_quote_number' => $analysis->quote_number,
                    'previous_premium_amount' => $analysis->premium_amount,
                    'previous_commercial_premium' => $analysis->commercial_premium,
                    'previous_gross_premium' => $analysis->gross_premium,
                    'previous_iof' => $analysis->iof,
                    'previous_insured_amount' => $analysis->insured_amount,
                    'previous_error_message' => $analysis->error_message,

                    'rent_amount' => $analysis->rent_amount,
                    'charges_amount' => $analysis->charges_amount,
                    'total_monthly_amount' => $analysis->total_monthly_amount,
                ],
                'response' => $analysis->response_payload,
            ]);

            $analysis->forceFill([
                'status' => 'pending',
                'result' => null,
                'provider_status' => null,

                /*
                |--------------------------------------------------------------------------
                | Retry técnico não é reanálise
                |--------------------------------------------------------------------------
                | Aqui limpamos proposta/cotação para o provider executar o fluxo normal.
                */
                'proposal_id' => null,
                'quote_id' => null,
                'quote_number' => null,
                'product_key' => null,

                'commercial_premium' => null,
                'gross_premium' => null,
                'iof' => null,

                'request_payload' => null,
                'response_payload' => null,

                'available_plans' => null,
                'available_assistances' => null,

                'premium_amount' => null,
                'insured_amount' => null,

                'error_message' => null,
                'requested_at' => null,
                'finished_at' => null,
            ])->save();

            if ($analysis->batch) {
                $completed = $analysis->batch->analyses()
                    ->whereIn('status', [
                        'quoted',
                        'approved',
                        'rejected',
                        'denied',
                        'refused',
                        'manual_review',
                    ])
                    ->count();

                $failed = $analysis->batch->analyses()
                    ->whereIn('status', ['failed', 'error'])
                    ->count();

                $analysis->batch->forceFill([
                    'status' => 'processing',
                    'completed_providers' => $completed,
                    'failed_providers' => $failed,
                    'finished_at' => null,
                    'started_at' => now(),
                ])->save();
            }
        });

        RunProviderAnalysisJob::dispatch(
            analysisId: $analysis->id,
            attemptId: $attemptId,
            isReanalysis: false,
            options: []
        );

        return $attemptId;
    }

    private function optionsForProvider(InsuranceAnalysis $analysis, array $options): array
    {
        if (! $analysis->isTooProvider()) {
            return $options;
        }

        $defaultReason = (int) config(
            'services.too.default_reanalysis_reason',
            10
        );

        $motivos = collect(
            $options['motivosReanalise'] ?? [$defaultReason]
        )->map(fn ($motivo) => (int) $motivo)
            ->filter(fn ($motivo) => $motivo > 0)
            ->unique()
            ->values()
            ->all();

        $observacoes = trim((string) (
            $options['observacoes']
            ?? 'Reanálise solicitada após alteração dos dados do lead.'
        ));

        if (blank($observacoes)) {
            throw new DomainException(
                'Informe uma observação para a reanálise da Too.'
            );
        }

        return [
            'motivosReanalise' => $motivos,
            'observacoes' => $observacoes,
        ];

    }
}
