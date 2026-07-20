<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class StartInsuranceAnalysesBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    private const PRODUCT_KEY = 'fianca_locaticia_residencial';

    public function __construct(
        public int $leadId,
        public bool $isReanalysis = false
    ) {}

    public function handle(InsuranceProviderResolver $resolver): void
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            logger()->notice('Job de análise ignorado porque o módulo está desativado.', ['job' => static::class]);

            return;
        }

        if ($this->isReanalysis) {
            throw new \LogicException(
                'Reanálises devem ser iniciadas pelo LeadReanalysisService.'
            );
        }

        $lead = Lead::with([
            'despesas',
            'endereco',
            'conjuge',
            'company',
            'locador',
            'imobiliariaInformada',  
        ])->findOrFail($this->leadId);

        $existingBatch = InsuranceAnalysisBatch::query()
        ->where('lead_id', $lead->id)
        ->latest('id')
        ->first();

        if($existingBatch) {
            Log::warning(
                'Análise inicial não iniciada porque o lead já possui lote.',
                [
                    'lead_id' => $lead->id,
                    'batch_id' => $existingBatch->id,
                    'batch_status' => $existingBatch->status,
                ]
            );

            return;
        }

        $providers = $resolver->availableProviders();

        if (empty($providers)) {
            Log::warning('Nenhum provider disponível para iniciar análises.', [
                'lead_id' => $lead->id,
                'is_reanalysis' => $this->isReanalysis,
            ]);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Identificador da rodada
        |--------------------------------------------------------------------------
        | Não muda o DER. Esse UUID fica salvo dentro do payload dos eventos.
        | Ele permite saber quais eventos/PDFs/e-mails pertencem à mesma rodada.
        */
        $attemptId = (string) Str::uuid();

        $rentAmount = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;
        $chargesAmount = $this->chargesAmount($lead);
        $totalMonthlyAmount = $rentAmount + $chargesAmount;

        $batchData = DB::transaction(function () use (
            $lead,
            $providers,
            $attemptId,
            $rentAmount,
            $chargesAmount,
            $totalMonthlyAmount
        ) {
            /*
            |--------------------------------------------------------------------------
            | Lote operacional do lead
            |--------------------------------------------------------------------------
            | A reanálise NÃO cria lote novo. Ela reaproveita o lote existente.
            */
            $batchModel = InsuranceAnalysisBatch::query()
                ->where('lead_id', $lead->id)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (!$batchModel) {
                $batchModel = InsuranceAnalysisBatch::create([
                    'lead_id' => $lead->id,
                    'company_id' => $lead->company_id,
                    'status' => 'processing',
                    'total_providers' => count($providers),
                    'completed_providers' => 0,
                    'failed_providers' => 0,
                    'started_at' => now(),
                    'finished_at' => null,
                ]);
            }
            $batchModel->update([
                'status' => 'processing',
                'total_providers' => count($providers),
                'completed_providers' => 0,
                'failed_providers' => 0,
                'started_at' => now(),
                'finished_at' => null,
            ]);
            

            $analysisIds = [];

            foreach ($providers as $provider) {
                /*
                |--------------------------------------------------------------------------
                | Uma análise por companhia dentro do lote
                |--------------------------------------------------------------------------
                | A reanálise reaproveita a análise da companhia e registra histórico
                | em eventos_analises_seguro.
                */
                $analysis = InsuranceAnalysis::query()
                    ->where('insurance_analysis_batch_id', $batchModel->id)
                    ->where('lead_id', $lead->id)
                    ->where('provider', $provider)
                    ->where('product', self::PRODUCT_KEY)
                    ->first();

                if (!$analysis) {
                    $analysis = InsuranceAnalysis::create([
                        'insurance_analysis_batch_id' => $batchModel->id,
                        'lead_id' => $lead->id,
                        'company_id' => $lead->company_id,

                        'provider' => $provider,
                        'product' => self::PRODUCT_KEY,

                        'status' => 'pending',

                        'plan_key' => 'traditional',
                        'multiple' => 30,

                        'lease_start_date' => now()->toDateString(),
                        'lease_end_date' => now()->copy()->addMonthsNoOverflow(30)->toDateString(),

                        'inhabited' => false,

                        'rent_amount' => $rentAmount,
                        'charges_amount' => $chargesAmount,
                        'total_monthly_amount' => $totalMonthlyAmount,

                        'payment_type' => config('services.pottencial.default_payment_type', 'Boleto'),
                        'installments' => (int) config('services.pottencial.default_installments', 12),
                    ]);

                    $analysis->events()->create([
                        'event_type' => 'created',
                        'status' => 'pending',
                        'message' => "Análise criada para provider {$provider}.",
                        'payload' => $this->attemptPayload(
                            attemptId: $attemptId,
                            lead: $lead,
                            provider: $provider,
                            rentAmount: $rentAmount,
                            chargesAmount: $chargesAmount,
                            totalMonthlyAmount: $totalMonthlyAmount
                        ),
                    ]);
                } else {
                    if ($this->isReanalysis) {
                        $analysis->events()->create([
                            'event_type' => 'previous_analysis_snapshot',
                            'status' => $analysis->status,
                            'message' => "Snapshot da análise anterior para provider {$provider}.",
                            'payload' => [
                                'attempt_id' => $attemptId,
                                'is_reanalysis' => true,
                                'snapshot_type' => 'before_reanalysis',

                                'analysis_id' => $analysis->id,
                                'batch_id' => $analysis->insurance_analysis_batch_id,
                                'lead_id' => $analysis->lead_id,
                                'company_id' => $analysis->company_id,

                                'provider' => $analysis->provider,
                                'product' => $analysis->product,

                                'previous_status' => $analysis->status,
                                'previous_result' => $analysis->result,

                                /*
                                |--------------------------------------------------------------------------
                                | Valores enviados na análise anterior
                                |--------------------------------------------------------------------------
                                */
                                'rent_amount' => (float) $analysis->rent_amount,
                                'charges_amount' => (float) $analysis->charges_amount,
                                'total_monthly_amount' => (float) $analysis->total_monthly_amount,

                                'plan_key' => $analysis->plan_key,
                                'multiple' => $analysis->multiple,
                                'lease_start_date' => optional($analysis->lease_start_date)->toDateString(),
                                'lease_end_date' => optional($analysis->lease_end_date)->toDateString(),

                                'captured_at' => now()->toDateTimeString(),
                            ],

                            /*
                            |--------------------------------------------------------------------------
                            | Valores retornados pela companhia na análise anterior
                            |--------------------------------------------------------------------------
                            | Aqui deixamos os campos normalizados para a view conseguir exibir
                            | sem depender do JSON bruto da API.
                            */
                            'response' => [
                                'provider' => $analysis->provider,
                                'provider_status' => $analysis->provider_status,
                                'quote_id' => $analysis->quote_id,
                                'quote_number' => $analysis->quote_number,
                                'product_key' => $analysis->product_key,

                                'premium_amount' => $analysis->premium_amount,
                                'commercial_premium' => $analysis->commercial_premium,
                                'gross_premium' => $analysis->gross_premium,
                                'iof' => $analysis->iof,
                                'insured_amount' => $analysis->insured_amount,

                                'available_plans' => $analysis->available_plans,
                                'available_assistances' => $analysis->available_assistances,
                            ],
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Evento de solicitação da nova rodada
                    |--------------------------------------------------------------------------
                    | Esse evento marca que uma reanálise foi solicitada.
                    */
                    $analysis->events()->create([
                        'event_type' => $this->isReanalysis ? 'reanalysis_requested' : 'analysis_restarted',
                        'status' => 'pending',
                        'message' => $this->isReanalysis
                            ? "Reanálise solicitada para provider {$provider}."
                            : "Nova execução de análise solicitada para provider {$provider}.",
                        'payload' => [
                            'attempt_id' => $attemptId,
                            'is_reanalysis' => $this->isReanalysis,
                            'requested_at' => now()->toDateTimeString(),
                        ],
                    ]);
                    /*
                    |--------------------------------------------------------------------------
                    | Reseta a análise atual para nova execução
                    |--------------------------------------------------------------------------
                    | Os valores antigos continuam preservados nos eventos.
                    */
                    $analysis->forceFill([
                        'status' => 'pending',
                        'result' => null,
                        'provider_status' => null,
                        'quote_id' => null,
                        'quote_number' => null,
                        'product_key' => null,

                        'available_plans' => null,
                        'available_assistances' => null,

                        'premium_amount' => null,
                        'commercial_premium' => null,
                        'gross_premium' => null,
                        'iof' => null,
                        'insured_amount' => null,

                        'response_payload' => null,
                        'error_message' => null,

                        'plan_key' => 'traditional',
                        'multiple' => 30,
                        'lease_start_date' => now()->toDateString(),
                        'lease_end_date' => now()->copy()->addMonthsNoOverflow(30)->toDateString(),
                        'inhabited' => false,
                        'rent_amount' => $rentAmount,
                        'charges_amount' => $chargesAmount,
                        'total_monthly_amount' => $totalMonthlyAmount,
                        'payment_type' => config('services.pottencial.default_payment_type', 'Boleto'),
                        'installments' => (int) config('services.pottencial.default_installments', 12),
                        'requested_at' => null,
                        'finished_at' => null,
                    ])->save();
                }

                $analysis->events()->create([
                    'event_type' => $this->isReanalysis ? 'reanalysis_started' : 'analysis_started',
                    'status' => 'pending',
                    'message' => $this->isReanalysis
                        ? "Reanálise iniciada para provider {$provider}."
                        : "Análise iniciada para provider {$provider}.",
                    'payload' => $this->attemptPayload(
                        attemptId: $attemptId,
                        lead: $lead,
                        provider: $provider,
                        rentAmount: $rentAmount,
                        chargesAmount: $chargesAmount,
                        totalMonthlyAmount: $totalMonthlyAmount
                    ),
                ]);

                $analysisIds[] = $analysis->id;
            }

            return [
                'batch_model_id' => $batchModel->id,
                'attempt_id' => $attemptId,
                'analysis_ids' => $analysisIds,
            ];
        });

        $jobs = collect($batchData['analysis_ids'])
            ->map(fn (int $analysisId) => new RunProviderAnalysisJob(
                analysisId: $analysisId,
                attemptId: $batchData['attempt_id'],
                isReanalysis: $this->isReanalysis
            ))->all();

        $isReanalysis = $this->isReanalysis;
        $batchModelId = (int) $batchData['batch_model_id'];
        $attemptId = (string) $batchData['attempt_id'];
        $leadId = (int) $lead->id;

        Bus::batch($jobs)
            ->name(
                $isReanalysis
                    ? "Reanálise do lead {$leadId}"
                    : "Análises do lead {$leadId}"
            )
            ->allowFailures()
            ->catch(static function (Batch $batch, Throwable $e) use ($batchModelId, $attemptId) {
                Log::warning('Erro em algum job do lote de análises', [
                    'batch_model_id' => $batchModelId,
                    'attempt_id' => $attemptId,
                    'laravel_batch_id' => $batch->id,
                    'message' => $e->getMessage(),
                ]);
            })
            ->finally(static function (Batch $batch) use ($batchModelId, $attemptId, $isReanalysis) {
                CompleteInsuranceAnalysesBatchJob::dispatch(
                    batchId: $batchModelId,
                    attemptId: $attemptId,
                    isReanalysis: $isReanalysis,
                );
            })
            ->dispatch();
    }

    private function attemptPayload(
        string $attemptId,
        Lead $lead,
        string $provider,
        float $rentAmount,
        float $chargesAmount,
        float $totalMonthlyAmount
    ): array {
        return [
            'attempt_id' => $attemptId,
            'is_reanalysis' => $this->isReanalysis,
            'provider' => $provider,

            'lead_id' => $lead->id,
            'lead_name' => $lead->nome,
            'lead_email' => $lead->email,

            'rent_amount' => $rentAmount,
            'charges_amount' => $chargesAmount,
            'total_monthly_amount' => $totalMonthlyAmount,

            'started_at' => now()->toDateTimeString(),
        ];
    }

    private function chargesAmount(Lead $lead): float
    {
        $rent = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;

        $valorAgua = $this->expenseValue($lead, 'valor_agua');
        $valorLuz = $this->expenseValue($lead, 'valor_luz');

        $agua = $valorAgua !== null
            ? $valorAgua
            : $rent * 0.10;

        $luz = $valorLuz !== null
            ? $valorLuz
            : $rent * 0.10;

        return ($this->expenseValue($lead, 'valor_condominio') ?? 0.0)
            + ($this->expenseValue($lead, 'valor_iptu') ?? 0.0)
            + ($this->expenseValue($lead, 'valor_gas') ?? 0.0)
            + $agua
            + $luz
            + ($this->expenseValue($lead, 'outras_despesas') ?? 0.0);
    }

    private function expenseValue(Lead $lead, string $field): ?float
    {
        $despesas = $lead->despesas;
        $value = $despesas->{$field} ?? $lead->{$field} ?? null;

        return $value !== null && $value !== '' ? (float) $value : null;
    }
}
