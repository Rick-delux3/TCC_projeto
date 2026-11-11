<?php

namespace App\Services\Insurance;

use App\Events\DashboardActivityChanged;
use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\SendLeadToLeadLoversJob;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Services\Leads\CompanyApplicantService;
use DomainException;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class LeadReanalysisService
{
    public function __construct(private CompanyApplicantService $companyApplicants) {}

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

    /**
     * Salva os dados do lead e sincroniza a LeadLovers. Não inicia análises:
     * a reanálise é sempre por companhia (startProviderReanalysis).
     */
    public function updateLeadData(
        Lead $lead,
        array $data,
        ?Corretor $corretor = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        $result = DB::transaction(function () use (
            $lead,
            $data,
            $corretor,
            $ip,
            $userAgent,
        ): array {
            $lead = Lead::query()
                ->with(['endereco', 'despesas', 'conjuge', 'lead_empresa', 'locador', 'imobiliariaInformada'])
                ->whereKey($lead->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previousLeadLoversUpdateStatus = $lead->leadlovers_update_status;
            $previousLeadLoversUpdateVersion = (int) $lead->leadlovers_update_version;

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

            if (array_intersect(['cpf', 'tipo_locacao'], array_keys($data)) !== []) {
                $lead->fill(\App\Support\CompanyConstitution::attributes(
                    array_key_exists('cpf', $data) ? $data['cpf'] : $lead->rentalApplicantDocument(),
                    array_key_exists('tipo_locacao', $data) ? $data['tipo_locacao'] : $lead->tipo_locacao?->value,
                    null,
                    $lead,
                ));
            }

            $lead->fill([
                'nome' => $data['nome'] ?? $lead->nome,
                'tel' => $submittedStringValue('tel', $lead->tel),
                'cpf' => $submittedStringValue('cpf', $lead->cpf),
                'tipo_solicitante' => $submittedStringValue('tipo_solicitante', $lead->tipo_solicitante),
                'estado_civil' => $submittedStringValue('estado_civil', $lead->estado_civil),
            ]);

            $additionalDetailsChanged = $corretor !== null
                && $this->updateAdminSimulationDetails($lead, $data);
            $removeSpouse = $corretor !== null
                && array_key_exists('estado_civil', $data)
                && ! in_array($lead->estado_civil, ['casado', 'uniao_estavel', 'divorciado', 'viuvo'], true);
            if ($removeSpouse) {
                $data['conjuge_nome'] = null;
                $data['conjuge_cpf'] = null;
            }

            $endereco = $lead->endereco ?: $lead->endereco()->make();
            $endereco->fill([
                'cep' => $submittedStringValue('cep', $endereco->cep),
                'estado' => $submittedStringValue('estado', $endereco->estado),
                'cidade_imovel' => $submittedStringValue('cidade_imovel', $endereco->cidade_imovel),
                'bairro' => $submittedStringValue('bairro', $endereco->bairro),
                'logradouro' => $submittedStringValue('logradouro', $endereco->logradouro),
                'numero' => $corretor !== null && array_key_exists('numero', $data)
                    ? ($data['numero'] ?? '123')
                    : $submittedStringValue('numero', $endereco->numero),
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
                'nome' => $removeSpouse ? null : $submittedStringValue('conjuge_nome', $conjuge->nome),
                'cpf' => $removeSpouse ? null : $submittedStringValue('conjuge_cpf', $conjuge->cpf),
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
            $conjugeChanged = $removeSpouse && $hadConjuge ? true : ($hadConjuge
                ? $conjuge->isDirty()
                : $hasMeaningfulValue($conjuge->only(['nome', 'cpf'])));
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
                ($removeSpouse && filled($originalRemoteValues['conjuge_cpf'])) || $submittedStringChanged(
                    'conjuge_cpf',
                    $originalRemoteValues['conjuge_cpf'],
                    $conjuge->cpf
                )
                    ? 'conjuge_cpf' : null,
            ]);

            if (! $leadChanged && ! $enderecoChanged && ! $despesasChanged && ! $conjugeChanged && ! $additionalDetailsChanged) {
                return [
                    'changed' => false,
                    'sync_status' => 'idle',
                    'dispatch' => null,
                ];
            }

            $lead->data_edited_at = now();
            $leadChanged = true;

            if ($corretor !== null) {
                $lead->updated_by_corretor_id = $corretor->id;
                $leadChanged = true;
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
                if ($removeSpouse) {
                    $lead->conjuge()->delete();
                } else {
                    $lead->conjuge()->save($conjuge);
                }
            }

            $this->companyApplicants->sync($lead);
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

                if ($corretor !== null) {
                    CorretorActivityLog::query()->create([
                        'corretor_id' => $corretor->id,
                        'action' => 'lead_data_update_requested',
                        'model_type' => Lead::class,
                        'model_id' => $lead->id,
                        'old_values' => [
                            'leadlovers_update_status' => $previousLeadLoversUpdateStatus,
                            'leadlovers_update_version' => $previousLeadLoversUpdateVersion,
                        ],
                        'new_values' => [
                            'leadlovers_update_status' => $syncStatus,
                            'leadlovers_update_version' => $syncVersion,
                            'requested_fields' => $requestedLeadLoversFields,
                        ],
                        'description' => 'Solicitou a alteração dos dados do lead e a sincronização com a LeadLovers.',
                        'ip' => $this->normalizedActivityIp($ip),
                        'user_agent' => $this->normalizedActivityUserAgent($userAgent),
                    ]);
                }

                $dispatch = [
                    'job_type' => 'update',
                    'lead_id' => (int) $lead->id,
                    'sync_version' => $syncVersion,
                    'requested_fields' => $requestedLeadLoversFields,
                    'not_before' => $this->leadLoversUpdateNotBefore($lead),
                ];
            }

            return [
                'changed' => true,
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
                $result['sync_status'] === 'pending'
                    ? 'lead.sync.processing'
                    : 'lead.updated',
            );
        }

        if (! $result['changed']) {
            return [
                'changed' => false,
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
                    $this->notifyDashboard(
                        (int) $dispatch['lead_id'],
                        'lead.sync.failed',
                    );
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

    private function normalizedActivityIp(?string $ip): ?string
    {
        if (blank($ip)) {
            return null;
        }

        return mb_substr(trim($ip), 0, 45);
    }

    private function normalizedActivityUserAgent(?string $userAgent): ?string
    {
        if (blank($userAgent)) {
            return null;
        }

        return mb_substr(trim($userAgent), 0, 2000);
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

    public const EXPENSE_FIELDS = ['valor_aluguel', 'valor_condominio', 'valor_iptu', 'valor_agua', 'valor_luz', 'valor_gas', 'outras_despesas'];

    public function expenseRevision(Lead $lead, InsuranceAnalysisBatch $batch): string
    {
        $lead->loadMissing('despesas');
        $batch->loadMissing('analyses.latestAttemptEvent');

        return hash('sha256', json_encode([
            $batch->id,
            $lead->despesas?->only(self::EXPENSE_FIELDS),
            $batch->analyses->sortBy('id')->map(fn (InsuranceAnalysis $analysis): array => [$analysis->id, $analysis->currentAttemptContext()])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function saveExpenses(Lead $lead, array $data, ?Corretor $corretor = null): array
    {
        $this->ensureAnalysisEnabled();

        return $lead->getConnection()->transaction(function () use ($lead, $data, $corretor): array {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            $batch = $lead->insuranceAnalysesBatches()->latest('id')->lockForUpdate()->first();
            if (! $batch) {
                throw new DomainException('Não há cotações para editar as despesas.');
            }
            $batch->load(['analyses' => fn ($query) => $query->orderBy('id')->lockForUpdate(), 'analyses.latestAttemptEvent']);
            $lead->load('despesas');
            if (! hash_equals($this->expenseRevision($lead, $batch), (string) ($data['revision'] ?? ''))) {
                throw new DomainException('As despesas ou cotações foram alteradas. Atualize a página antes de salvar.');
            }
            $values = $this->normalizedExpenseValues($data);
            if ($this->expensesDiffer($values, $lead->despesas?->only(self::EXPENSE_FIELDS) ?? [])) {
                $this->saveLeadExpenses($lead, $values, $corretor, false);
            }

            return ['expenses' => $values, 'revision' => $this->expenseRevision($lead, $batch)];
        });
    }

    /**
     * Reanalisa somente uma companhia com as despesas informadas, mantendo as demais intactas.
     *
     * @param  array<string, mixed>  $data
     */
    public function reanalyzeProviderExpenses(InsuranceAnalysis $analysis, array $data, string $requestedBy, ?Corretor $corretor = null): void
    {
        $this->ensureReanalysisAllowed($analysis);

        $analysis->getConnection()->transaction(function () use ($analysis, $data, $requestedBy, $corretor): void {
            $lead = Lead::query()->lockForUpdate()->findOrFail($analysis->lead_id);
            $batch = $lead->insuranceAnalysesBatches()->latest('id')->lockForUpdate()->first();
            $analysis = InsuranceAnalysis::query()->with('currentProviderRun.round')->lockForUpdate()->findOrFail($analysis->id);
            if (! $batch || (int) $analysis->insurance_analysis_batch_id !== (int) $batch->id) {
                throw new DomainException('Esta cotação não pertence ao pacote atual do lead. Atualize a página.');
            }
            if (! ProviderAnalysisStatus::isTerminal($analysis->status)) {
                throw new DomainException('Esta companhia ainda está em análise. Aguarde o resultado para reanalisar.');
            }
            $lead->load('despesas');
            $values = $this->normalizedExpenseValues($data);
            $previous = data_get($analysis->currentProviderRun?->round?->lead_snapshot, 'relations.despesas')
                ?? $lead->despesas?->only(self::EXPENSE_FIELDS) ?? [];
            if (! $this->expensesDiffer($values, is_array($previous) ? $previous : [])) {
                throw new DomainException('Altere pelo menos uma despesa em relação à última cotação desta companhia.');
            }
            if ($this->expensesDiffer($values, $lead->despesas?->only(self::EXPENSE_FIELDS) ?? [])) {
                $this->saveLeadExpenses($lead, $values, $corretor);
            }

            $this->dispatchExpenseReanalysis($lead, $batch, [$analysis], $requestedBy, "Reanálise de {$analysis->provider} do lead {$lead->id}");
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function normalizedExpenseValues(array $data): array
    {
        return collect(self::EXPENSE_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => number_format((float) ($data[$field] ?? 0), 2, '.', '')])
            ->all();
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $previous
     */
    private function expensesDiffer(array $values, array $previous): bool
    {
        return collect($values)->contains(fn (string $value, string $field): bool => $value !== number_format((float) ($previous[$field] ?? 0), 2, '.', ''));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function saveLeadExpenses(Lead $lead, array $values, ?Corretor $corretor, bool $reanalyzing = true): void
    {
        $expenses = $lead->despesas ?? $lead->despesas()->make();
        $totalCents = collect($values)->sum(fn (string $value): int => (int) round((float) $value * 100));
        $expenses->fill($values + ['valor_total_encargos' => $totalCents / 100])->save();
        $lead->setRelation('despesas', $expenses);
        $lead->data_edited_at = now();
        if ($reanalyzing) {
            $lead->forceFill(['status' => 'em-andamento']);
        }
        if ($corretor) {
            $lead->updated_by_corretor_id = $corretor->id;
        }
        $lead->save();
    }

    /**
     * Reinicia as companhias informadas na mesma tentativa e agenda a conclusão do pacote após o commit.
     * Companhias aprovadas/recusadas seguem o fluxo de reanálise; as demais (falha ou cotação manual) recebem nova análise.
     *
     * @param  list<InsuranceAnalysis>  $analyses
     */
    private function dispatchExpenseReanalysis(Lead $lead, InsuranceAnalysisBatch $batch, array $analyses, string $requestedBy, string $name): int
    {
        $attemptId = (string) Str::uuid();
        $jobs = [];
        foreach ($analyses as $analysis) {
            $usesReanalysisFlow = $analysis->canRequestProviderReanalysis();
            $this->restartAnalysisAsReanalysis(
                analysis: $analysis, message: 'Nova cotação solicitada após alteração das despesas.',
                attemptId: $attemptId, requestedBy: $requestedBy, replaceCurrentAttempt: true,
            );
            $jobs[] = new RunProviderAnalysisJob($analysis->id, $attemptId, $usesReanalysisFlow);
        }
        $batchId = (int) $batch->id;
        $leadId = (int) $lead->id;
        $lead->getConnection()->afterCommit(function () use ($jobs, $batchId, $leadId, $attemptId, $name): void {
            Bus::batch($jobs)->onQueue('insurance-analyses')->name($name)->allowFailures()
                ->finally(static function (Batch $batch) use ($batchId, $attemptId): void {
                    CompleteInsuranceAnalysesBatchJob::dispatch($batchId, $attemptId, true);
                })->dispatch();
            $this->notifyDashboard($leadId, 'lead.reanalysis.requested');
        });

        return count($jobs);
    }

    public function startProviderReanalysis(
        InsuranceAnalysis $analysis,
        string $requestedBy = 'imobiliaria',
    ): string {
        $this->ensureReanalysisAllowed($analysis);
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

        $attemptId = (string) Str::uuid();

        $this->restartAnalysisAsReanalysis(
            analysis: $analysis,
            attemptId: $attemptId,
            message: 'Reanálise por companhia solicitada após alteração dos dados do lead.',
            requestedBy: $requestedBy,
        );

        RunProviderAnalysisJob::dispatch(
            analysisId: $analysis->id,
            attemptId: $attemptId,
            isReanalysis: true,
        );

        return $attemptId;
    }

    private function updateAdminSimulationDetails(Lead $lead, array $data): bool
    {
        $changed = false;
        if (array_key_exists('tipo_locacao', $data)) {
            $lead->tipo_locacao = $data['tipo_locacao'];
        }
        if (array_key_exists('tipo_locacao', $data) || array_key_exists('descrever_atividade', $data)) {
            $lead->descrever_atividade = $lead->tipo_locacao?->value === 'comercial'
                ? ($data['descrever_atividade'] ?? $lead->descrever_atividade)
                : null;
        }

        if (array_intersect(['cpf', 'cpf_responsavel', 'nome_responsavel'], array_keys($data)) !== []) {
            $document = $data['cpf'] ?? $lead->lead_empresa?->cnpj;
            if (array_key_exists('cpf', $data)) {
                $document = $data['cpf'];
            }
            if (is_string($document) && preg_match('/^\d{14}$/D', $document) === 1) {
                $company = $lead->lead_empresa ?: $lead->lead_empresa()->make();
                $company->cnpj = $document;
                foreach (['cpf_responsavel', 'nome_responsavel'] as $field) {
                    if (array_key_exists($field, $data)) {
                        $company->{$field} = $data[$field];
                    }
                }
                $lead->cpf = null;
                if ($company->isDirty()) {
                    $lead->lead_empresa()->save($company);
                    $changed = true;
                }
            } elseif (array_key_exists('cpf', $data) && $lead->lead_empresa !== null) {
                $lead->lead_empresa()->delete();
                $changed = true;
            }
        }

        $requesterFields = ['tipo_solicitante', 'responsavel_nome', 'responsavel_email', 'responsavel_telefone', 'responsavel_preenchimento'];
        if (array_intersect($requesterFields, array_keys($data)) === []) {
            return $changed;
        }
        $profile = $lead->tipo_solicitante;
        $relation = match ($profile) {
            'locador' => 'locador',
            'imobiliaria_nao_cadastrada', 'imobiliaria_cadastrada' => 'imobiliariaInformada',
            default => null,
        };
        $mapping = match ($profile) {
            'locador' => ['responsavel_nome' => 'nome', 'responsavel_email' => 'email', 'responsavel_telefone' => 'telefone'],
            'imobiliaria_nao_cadastrada' => ['responsavel_nome' => 'nome_imobiliaria_informada', 'responsavel_email' => 'responsavel_preenchimento', 'responsavel_telefone' => 'telefone_responsavel'],
            'imobiliaria_cadastrada' => ['responsavel_preenchimento' => 'responsavel_preenchimento'],
            default => [],
        };
        if ($relation !== null) {
            $requester = $lead->{$relation} ?: $lead->{$relation}()->make();
            foreach ($mapping as $input => $column) {
                if (array_key_exists($input, $data)) {
                    $requester->{$column} = $data[$input];
                }
            }
            if ($requester->isDirty() && ($requester->exists || collect($mapping)->contains(fn ($column) => filled($requester->{$column})))) {
                $lead->{$relation}()->save($requester);
                $changed = true;
            }
        }
        if (array_key_exists('tipo_solicitante', $data)) {
            foreach (['locador', 'imobiliariaInformada'] as $otherRelation) {
                if ($otherRelation !== $relation && $lead->{$otherRelation} !== null) {
                    $lead->{$otherRelation}()->delete();
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    /**
     * Valida, antes de qualquer gravação, se a companhia pode ser reanalisada.
     * Pottencial e Too não aceitam reanálise: vinculam o objeto de risco à
     * cotação anterior e não recalculam só as coberturas.
     */
    public function ensureReanalysisAllowed(InsuranceAnalysis $analysis): void
    {
        $this->ensureAnalysisEnabled();
        $this->ensureProviderEnabled($analysis);
        if (! $analysis->supportsProviderReanalysis()) {
            throw new DomainException(self::providerLabel((string) $analysis->provider).' não permite reanálise. A cotação anterior permanece registrada; as companhias que aceitam reanálise são a Porto Seguro e a Tokio Marine.');
        }
    }

    private static function providerLabel(string $provider): string
    {
        return match (mb_strtolower($provider)) {
            'pottencial' => 'A Pottencial',
            'too' => 'A Too Seguros',
            'tokio' => 'A Tokio Marine',
            'porto' => 'A Porto Seguro',
            default => 'Esta companhia',
        };
    }

    /** Ações por companhia exigem a seguradora ligada no .env; o resultado anterior segue só para leitura. */
    private function ensureProviderEnabled(InsuranceAnalysis $analysis): void
    {
        if (! \App\Services\Insurance\Providers\InsuranceProviderResolver::providerEnabled((string) $analysis->provider)) {
            throw new DomainException('Esta companhia está desativada no momento. O resultado anterior permanece apenas para consulta.');
        }
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
        array $options = [],
        bool $replaceCurrentAttempt = false
    ): string {
        DB::transaction(function () use (
            $analysis,
            $attemptId,
            $message,
            $requestedBy,
            $options,
            $replaceCurrentAttempt
        ) {
            Lead::query()->lockForUpdate()->findOrFail($analysis->lead_id);
            if ($analysis->insurance_analysis_batch_id) {
                InsuranceAnalysisBatch::query()->lockForUpdate()->findOrFail($analysis->insurance_analysis_batch_id);
            }
            $analysis = InsuranceAnalysis::query()
                ->whereKey($analysis->id)
                ->lockForUpdate()
                ->firstOrFail();

            $analysis->loadMissing([
                'batch.analyses',
                'lead.despesas',
                'events',
            ]);

            if (! $analysis->supportsProviderReanalysis()
                || ($replaceCurrentAttempt ? ! ProviderAnalysisStatus::isTerminal($analysis->status) : ! $analysis->canRequestProviderReanalysis())) {
                throw new DomainException(
                    'Esta análise já está sendo processada ou não permite reanálise.'
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

            app(InsuranceQuoteCoordinator::class)->prepare($analysis, $attemptId, true);
        });

        return $attemptId;
    }

    public function startTechnicalRetry(
        InsuranceAnalysis $analysis,
        string $requestedBy = 'imobiliaria'
    ): string {
        $this->ensureProviderEnabled($analysis);
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
            Lead::query()->lockForUpdate()->findOrFail($analysis->lead_id);
            if ($analysis->insurance_analysis_batch_id) {
                InsuranceAnalysisBatch::query()->lockForUpdate()->findOrFail($analysis->insurance_analysis_batch_id);
            }
            $analysis = InsuranceAnalysis::query()->lockForUpdate()->findOrFail($analysis->id);
            if (! in_array($analysis->status, ['failed', 'error'], true)) {
                throw new DomainException('O reenvio técnico já foi iniciado ou não está mais disponível.');
            }
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
            app(InsuranceQuoteCoordinator::class)->prepare($analysis, $attemptId, false);
        });

        RunProviderAnalysisJob::dispatch(
            analysisId: $analysis->id,
            attemptId: $attemptId,
            isReanalysis: false,
            options: []
        );

        return $attemptId;
    }

    private function notifyDashboard(int $leadId, string $change): void
    {
        $lead = Lead::query()
            ->select(['id', 'company_id'])
            ->find($leadId);

        if ($lead === null) {
            return;
        }

        DashboardActivityChanged::dispatch(
            'lead',
            (int) $lead->id,
            $lead->company_id !== null
                ? (int) $lead->company_id
                : null,
            $change,
        );
    }
}
