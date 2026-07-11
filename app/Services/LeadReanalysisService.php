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

        $batch = InsuranceAnalysisBatch::with([
                'analyses.events',
                'analyses.lead.endereco',
                'analyses.lead.despesas',
                'analyses.lead.conjuge',
            ])
            ->where('lead_id', $lead->id)
            ->latest()
            ->first();

        if (! $batch) {
            throw new DomainException('Nenhum lote de análise encontrado para este lead.');
        }

        $eligibleAnalyses = $batch->analyses
            ->filter(fn (InsuranceAnalysis $analysis) => $analysis->canRequestProviderReanalysis())
            ->values();

        if ($eligibleAnalyses->isEmpty()) {
            throw new DomainException(
                'Nenhuma companhia deste lead está em status permitido para reanálise.'
            );
        }

        foreach ($eligibleAnalyses as $analysis) {
            $providerOptions = $this->optionsForProvider($analysis, $options);

            $attemptId = $this->restartAnalysisAsReanalysis(
                analysis: $analysis,
                message: 'Reanálise geral solicitada após alteração dos dados do lead.',
                requestedBy: $requestedBy,
                options: $providerOptions
            );

            RunProviderAnalysisJob::dispatch(
                analysisId: $analysis->id,
                attemptId: $attemptId,
                isReanalysis: true,
                options: $providerOptions
            );
        }

        $lead->forceFill([
            'status' => 'em-andamento',
            'reanalysis_unlocked_at' => null,
        ])->save();

        return $eligibleAnalyses->count();
    }

    public function startProviderReanalysis(
        InsuranceAnalysis $analysis,
        string $requestedBy = 'imobiliaria',
        array $options = []
    ): string {
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

        $attemptId = $this->restartAnalysisAsReanalysis(
            analysis: $analysis,
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

    public function restartAnalysisAsReanalysis(
        InsuranceAnalysis $analysis,
        string $message,
        string $requestedBy = 'imobiliaria',
        array $options = []
    ): string {
        $attemptId = (string) Str::uuid();

        $analysis->loadMissing([
            'batch.analyses',
            'lead.despesas',
            'events',
        ]);

        $isToo = $analysis->isTooProvider();

        $previousResponsePayload = $analysis->response_payload ?? [];

        if (is_string($previousResponsePayload)) {
            $previousResponsePayload = json_decode($previousResponsePayload, true) ?: [];
        }

        $tooNumeroProposta = data_get($previousResponsePayload, 'numeroProposta')
            ?? data_get($previousResponsePayload, 'numero_proposta')
            ?? $analysis->proposal_id;

        $tooNumeroFicha = data_get($previousResponsePayload, 'numeroFicha')
            ?? data_get($previousResponsePayload, 'numero_ficha')
            ?? data_get($previousResponsePayload, 'numeroProposta')
            ?? $analysis->proposal_id;

        DB::transaction(function () use (
            $analysis,
            $attemptId,
            $message,
            $requestedBy,
            $options,
            $isToo,
            $previousResponsePayload,
            $tooNumeroProposta,
            $tooNumeroFicha
        ) {
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
                'quote_id' => null,
                'quote_number' => null,
                'product_key' => null,
                'commercial_premium' => null,
                'gross_premium' => null,
                'iof' => null,
                'request_payload' => null,

                /*
                |--------------------------------------------------------------------------
                | Ponto crítico da Too
                |--------------------------------------------------------------------------
                | Não podemos apagar numeroProposta/numeroFicha.
                */
                'response_payload' => $isToo
                    ? array_merge($previousResponsePayload, [
                        'numeroProposta' => $tooNumeroProposta,
                        'numeroFicha' => $tooNumeroFicha,
                        'too_reanalysis_started_at' => now()->toDateTimeString(),
                        'too_reanalysis_attempt_id' => $attemptId,
                    ])
                    : null,

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

        return $attemptId;
    }

    private function optionsForProvider(InsuranceAnalysis $analysis, array $options): array
    {
        if (! $analysis->isTooProvider()) {
            return $options;
        }

        return [
            'motivosReanalise' => $options['motivosReanalise'] ?? [10],
            'observacoes' => $options['observacoes']
                ?? 'Reanálise solicitada após alteração dos dados do lead.',
        ];
    }
}