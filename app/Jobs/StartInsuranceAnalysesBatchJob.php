<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
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

    public function __construct(
        public int $leadId
    ) {}

    public function handle(InsuranceProviderResolver $resolver): void
    {
        $lead = Lead::findOrFail($this->leadId);

        $providers = $resolver->availableProviders();

        $batchModel = InsuranceAnalysisBatch::create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'status' => 'processing',
            'total_providers' => count($providers),
            'completed_providers' => 0,
            'failed_providers' => 0,
            'started_at' => now(),
        ]);

        $jobs = [];

        $rentAmount = (float) ($lead->valor_aluguel ?? 0);
        $chargesAmount = $this->chargesAmount($lead);
        $totalMonthlyAmount = $rentAmount + $chargesAmount;

        foreach ($providers as $provider) {
            $analysis = InsuranceAnalysis::create([
                'insurance_analysis_batch_id' => $batchModel->id,
                'lead_id' => $lead->id,
                'company_id' => $lead->company_id,

                'provider' => $provider,
                'product' => 'fianca_locaticia_residencial',

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
            ]);

            $jobs[] = new RunProviderAnalysisJob($analysis->id);
        }

        Bus::batch($jobs)
        ->name("Análises do lead {$lead->id}")
        ->allowFailures()
        ->catch(function (Batch $batch, Throwable $e) use ($batchModel) {
            Log::warning('Erro em algum job do lote de análises', [
                'batch_model_id' => $batchModel->id,
                'laravel_batch_id' => $batch->id,
                'message' => $e->getMessage(),
            ]);
        })
        ->finally(function (Batch $batch) use ($batchModel) {
            CompleteInsuranceAnalysesBatchJob::dispatch($batchModel->id);
        })
        ->dispatch();
    }

    private function chargesAmount(Lead $lead): float
    {
        $rent = (float) ($lead->valor_aluguel ?? 0);

        $agua = $lead->valor_agua !== null
            ? (float) $lead->valor_agua
            : $rent * 0.10;

        $luz = $lead->valor_luz !== null
            ? (float) $lead->valor_luz
            : $rent * 0.10;

        return (float) ($lead->valor_condominio ?? 0)
            + (float) ($lead->valor_iptu ?? 0)
            + (float) ($lead->valor_gas ?? 0)
            + $agua
            + $luz
            + (float) ($lead->outras_despesas ?? 0);
    }
}