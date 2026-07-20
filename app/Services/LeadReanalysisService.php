<?php

namespace App\Services;

use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\UpdateLeadOnLeadLoversJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Jobs\CompleteInsuranceAnalysesBatchJob;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

class LeadReanalysisService
{
    public function updateLeadDataAndMaybeUnlock(Lead $lead, array $data): array
    {
        $lead->loadMissing([
            'endereco',
            'despesas',
            'conjuge',
        ]);

        $originalEmail = $lead->email;

        $lead->fill([
            'nome' => $data['nome'] ?? $lead->nome,
            'email' => $data['email'] ?? $lead->email,
            'tel' => $data['tel'] ?? $lead->tel,
            'cpf' => $data['cpf'] ?? $lead->cpf,
            'tipo_solicitante' => $data['tipo_solicitante'] ?? $lead->tipo_solicitante,
            'estado_civil' => $data['estado_civil'] ?? $lead->estado_civil,
        ]);

        $endereco = $lead->endereco ?: $lead->endereco()->make();

        $endereco->fill([
            'cep' => $data['cep'] ?? $endereco->cep,
            'estado' => $data['estado'] ?? $endereco->estado,
            'cidade_imovel' => $data['cidade_imovel'] ?? $endereco->cidade_imovel,
            'bairro' => $data['bairro'] ?? $endereco->bairro,
            'logradouro' => $data['logradouro'] ?? $endereco->logradouro,
            'numero' => $data['numero'] ?? $endereco->numero,
            'complemento' => $data['complemento'] ?? $endereco->complemento,
        ]);

        $despesas = $lead->despesas ?: $lead->despesas()->make();

        $valorAluguel = (float) ($data['valor_aluguel'] ?? $despesas->valor_aluguel ?? 0);
        $valorAgua = (float) ($data['valor_agua'] ?? $despesas->valor_agua ?? 0);
        $valorLuz = (float) ($data['valor_luz'] ?? $despesas->valor_luz ?? 0);
        $valorGas = (float) ($data['valor_gas'] ?? $despesas->valor_gas ?? 0);
        $valorCondominio = (float) ($data['valor_condominio'] ?? $despesas->valor_condominio ?? 0);
        $valorIptu = (float) ($data['valor_iptu'] ?? $despesas->valor_iptu ?? 0);
        $outrasDespesas = (float) ($data['outras_despesas'] ?? $despesas->outras_despesas ?? 0);

        $valorTotalEncargos =
            $valorAluguel
            + $valorAgua
            + $valorLuz
            + $valorGas
            + $valorCondominio
            + $valorIptu
            + $outrasDespesas;

        $despesas->fill([
            'valor_aluguel' => $valorAluguel,
            'valor_agua' => $valorAgua,
            'valor_luz' => $valorLuz,
            'valor_gas' => $valorGas,
            'valor_condominio' => $valorCondominio,
            'valor_iptu' => $valorIptu,
            'outras_despesas' => $outrasDespesas,
            'valor_total_encargos' => $valorTotalEncargos,
        ]);

        $conjuge = $lead->conjuge ?: $lead->conjuge()->make();

        $conjuge->fill([
            'nome' => $data['conjuge_nome'] ?? $conjuge->nome,
            'cpf' => $data['conjuge_cpf'] ?? $conjuge->cpf,
        ]);

        $leadChanged = $lead->isDirty();
        $enderecoChanged = $endereco->isDirty();
        $despesasChanged = $despesas->isDirty();
        $conjugeChanged = $conjuge->isDirty();

        if (! $leadChanged && ! $enderecoChanged && ! $despesasChanged && ! $conjugeChanged) {
            return [
                'changed' => false,
                'unlocked' => false,
                'message' => 'Altere pelo menos um dado do lead antes de salvar.',
            ];
        }

        $unlocked = false;

        DB::transaction(function () use (
            $lead,
            $endereco,
            $despesas,
            $conjuge,
            $leadChanged,
            $enderecoChanged,
            $despesasChanged,
            $conjugeChanged,
            &$unlocked
        ) {
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

            /*
            |--------------------------------------------------------------------------
            | Nova regra de desbloqueio
            |--------------------------------------------------------------------------
            | Só libera reanálise geral se o lead já teve resultado final aprovado
            | ou recusado.
            */
            if ($lead->hasFinalInsuranceResultForReanalysis()) {
                $lead->forceFill([
                    'reanalysis_unlocked_at' => now(),
                ])->saveQuietly();

                $unlocked = true;
            }
        });

        try {
            UpdateLeadOnLeadLoversJob::dispatchAfterResponse($lead->id, $originalEmail);
        } catch (\Throwable $exception) {
            Log::warning('Lead atualizado localmente, mas falhou ao enfileirar atualização na LeadLovers.', [
                'lead_id' => $lead->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return [
            'changed' => true,
            'unlocked' => $unlocked,
            'message' => $unlocked
                ? 'Dados do lead atualizados com sucesso. Agora você pode solicitar uma reanálise.'
                : 'Dados do lead atualizados com sucesso. A reanálise só ficará disponível quando o lead tiver resultado final aprovado ou recusado.',
        ];
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

            if(! $batch) {
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

            if($eligibleAnalyses->isEmpty()){
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
            ->finally(static function (Batch $batch) use ($batchId, $attemptId){
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
