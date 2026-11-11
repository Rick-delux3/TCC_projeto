<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class StartInsuranceAnalysesBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    /** Somente a análise inicial: a reanálise é por companhia (LeadReanalysisService). */
    public function __construct(
        public int $leadId,
    ) {
        $this->onQueue('insurance-analyses');
    }

    public function handle(InsuranceProviderResolver $resolver): void
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            logger()->notice('Job de análise ignorado porque o módulo está desativado.', ['job' => static::class]);

            return;
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

        if ($existingBatch) {
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

        DB::transaction(function () use (
            $lead,
            $providers,
            $attemptId,
            $rentAmount,
            $chargesAmount,
            $totalMonthlyAmount
        ) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);

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

            if ($batchModel) {
                return null;
            }

            if (! $batchModel) {
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
                    ->where('product', $lead->rentalGuaranteeProduct())
                    ->first();

                if (! $analysis) {
                    $analysis = InsuranceAnalysis::create([
                        'insurance_analysis_batch_id' => $batchModel->id,
                        'lead_id' => $lead->id,
                        'company_id' => $lead->company_id,

                        'provider' => $provider,
                        'product' => $lead->rentalGuaranteeProduct(),

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
                        'installments' => (int) config('services.pottencial.default_installments', 30),
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
                    /*
                    |--------------------------------------------------------------------------
                    | Evento de solicitação da nova rodada
                    |--------------------------------------------------------------------------
                    | Esse evento marca uma nova execução da análise inicial.
                    */
                    $analysis->events()->create([
                        'event_type' => 'analysis_restarted',
                        'status' => 'pending',
                        'message' => "Nova execução de análise solicitada para provider {$provider}.",
                        'payload' => [
                            'attempt_id' => $attemptId,
                            'is_reanalysis' => false,
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
                        'installments' => (int) config('services.pottencial.default_installments', 30),
                        'requested_at' => null,
                        'finished_at' => null,
                    ])->save();
                }

                $analysis->events()->create([
                    'event_type' => 'analysis_started',
                    'status' => 'pending',
                    'message' => "Análise iniciada para provider {$provider}.",
                    'payload' => $this->attemptPayload(
                        attemptId: $attemptId,
                        lead: $lead,
                        provider: $provider,
                        rentAmount: $rentAmount,
                        chargesAmount: $chargesAmount,
                        totalMonthlyAmount: $totalMonthlyAmount
                    ),
                ]);

                app(\App\Services\Insurance\InsuranceQuoteCoordinator::class)->prepare($analysis, $attemptId, false);
                $analysisIds[] = $analysis->id;
            }

            $connection = config('queue.default');
            $database = DB::connection()->getName();
            if (config("queue.connections.{$connection}.driver") !== 'database'
                || (config("queue.connections.{$connection}.connection") ?: $database) !== $database
                || (config('queue.batching.database') ?: $database) !== $database) {
                throw new \LogicException('Os pacotes de análise exigem a fila database na mesma conexão de banco da aplicação.');
            }

            $jobs = collect($analysisIds)
                ->map(fn (int $analysisId) => (new RunProviderAnalysisJob(
                    analysisId: $analysisId,
                    attemptId: $attemptId,
                    isReanalysis: false
                ))->beforeCommit())->all();
            $batchModelId = (int) $batchModel->id;
            $leadId = (int) $lead->id;

            Bus::batch($jobs)
                ->onConnection($connection)
                ->onQueue('insurance-analyses')
                ->name("Análises do lead {$leadId}")
                ->allowFailures()
                ->catch(static function (Batch $batch, Throwable $e) use ($batchModelId, $attemptId) {
                    Log::warning('Erro em algum job do lote de análises', [
                        'batch_model_id' => $batchModelId,
                        'attempt_id' => $attemptId,
                        'laravel_batch_id' => $batch->id,
                        'message' => $e->getMessage(),
                    ]);
                })
                ->finally(static function (Batch $batch) use ($batchModelId, $attemptId) {
                    CompleteInsuranceAnalysesBatchJob::dispatch(
                        batchId: $batchModelId,
                        attemptId: $attemptId,
                        isReanalysis: false,
                    );
                })
                ->dispatch();
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Não foi possível despachar o pacote inicial de análises.', [
            'lead_id' => $this->leadId,
            'exception' => $exception ? $exception::class : null,
        ]);
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
            'is_reanalysis' => false,
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
