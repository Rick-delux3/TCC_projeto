<?php

namespace App\Jobs;

use App\Exceptions\InvalidInsuranceAnalysisPayload;
use App\Exceptions\ObsoleteInsuranceAnalysisAttempt;
use App\Models\InsuranceAnalysis;
use App\Services\Insurance\InsuranceAnalysisAttempt;
use App\Services\Insurance\InsuranceAnalysisFailure;
use App\Services\Insurance\InsuranceStatusPolling;
use App\Services\Insurance\ProviderAnalysisStatus;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunProviderAnalysisJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $timeout = 180;

    public int $maxExceptions = 3;

    public array $backoff = [30, 120, 300];

    public bool $schedulePolling = true;

    public ?array $providerResult = null;

    public function __construct(
        public int $analysisId,
        public string $attemptId,
        public bool $isReanalysis = false,
        public array $options = []
    ) {
        $this->onQueue('insurance-analyses');
    }

    public function handle(InsuranceProviderResolver $resolver): void
    {
        try {
            $this->execute($resolver);
        } catch (ObsoleteInsuranceAnalysisAttempt) {
            return;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        InsuranceAnalysisFailure::finish($this->analysisId, $this->attemptId, $this->isReanalysis, $exception);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("insurance-analysis:{$this->analysisId}"))->shared()->releaseAfter(30)->expireAfter(210)];
    }

    private function execute(InsuranceProviderResolver $resolver): void
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            logger()->notice('Job de análise ignorado porque o módulo está desativado.', ['job' => static::class]);

            return;
        }

        $analysis = InsuranceAnalysis::with([
            'lead.company',
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'lead.locador',
            'lead.imobiliariaInformada',
            'batch',
        ])->findOrFail($this->analysisId);

        $coordinator = app(\App\Services\Insurance\InsuranceQuoteCoordinator::class);
        $run = $coordinator->findRun($analysis, $this->attemptId);
        if ($run) {
            $coordinator->dispatchPending($run);

            return;
        }

        InsuranceAnalysisAttempt::run($analysis, $this->attemptId, function () use ($analysis): void {
            if ($analysis->status !== 'pending' && ! ($analysis->status === 'processing' && $this->attempts() > 1)) {
                throw new ObsoleteInsuranceAnalysisAttempt('Esta execução já foi iniciada.');
            }
            $analysis->update([
                'status' => 'processing',
                'requested_at' => now(),
                'error_message' => null,
            ]);

            $analysis->events()->create([
                'event_type' => $this->isReanalysis ? 'reanalysis_sent_to_api' : 'sent_to_api',
                'status' => 'processing',
                'message' => $this->isReanalysis
                    ? "Enviando reanálise para {$analysis->provider}."
                    : "Enviando análise para {$analysis->provider}.",
                'payload' => $this->analysisSnapshot($analysis),
            ]);

        });

        try {
            $analysis->updateForAttempt(['product' => $analysis->lead->rentalGuaranteeProduct()]);
            $provider = $resolver->resolve($analysis->provider);

            if ($this->attempts() > 1 && ! $analysis->isTooProvider() && filled($analysis->quote_id)) {
                $result = $provider->getStatus($analysis);
            } elseif ($this->isReanalysis) {
                $result = $provider->requestReanalysis(
                    analysis: $analysis,
                    attemptId: $this->attemptId,
                    options: $this->options ?? []

                );
            } else {
                $result = $provider->requestAnalysis(
                    analysis: $analysis,
                    attemptId: $this->attemptId
                );
            }

            $this->providerResult = $result;

            if ($this->shouldRetryResult($result)) {
                throw new \RuntimeException('Falha temporária na comunicação com a companhia.');
            }

            Log::info('Resultado bruto recebido do provider', [
                'analysis_id' => $analysis->id,
                'attempt_id' => $this->attemptId,
                'is_reanalysis' => $this->isReanalysis,
                'provider' => $analysis->provider,
                'result' => $result,
            ]);

            InsuranceAnalysisAttempt::run($analysis, $this->attemptId, function () use ($analysis, $result): void {
                $this->applyResult($analysis, $result);
                if ($this->schedulePolling) {
                    InsuranceStatusPolling::schedule($analysis->fresh(), $this->attemptId, $this->isReanalysis);
                }
            });
        } catch (ObsoleteInsuranceAnalysisAttempt $obsolete) {
            throw $obsolete;
        } catch (InvalidInsuranceAnalysisPayload|\DomainException|\InvalidArgumentException $exception) {
            $this->failed($exception);
        } catch (\Throwable $exception) {
            InsuranceAnalysisAttempt::run($analysis, $this->attemptId, function () use ($analysis, $exception): void {
                if (ProviderAnalysisStatus::isTerminal($analysis->status)) {
                    return;
                }
                $analysis->update([
                    'status' => 'processing',
                    'finished_at' => null,
                    'error_message' => null,
                ]);
                $analysis->events()->create([
                    'event_type' => 'execution_retry',
                    'status' => 'processing',
                    'message' => 'Falha temporária. A fila realizará uma nova tentativa.',
                    'payload' => array_merge($this->analysisSnapshot($analysis), [
                        'queue_attempt' => $this->attempts(),
                        'exception' => $exception::class,
                    ]),
                ]);
            });
            throw $exception;
        }
    }

    private function shouldRetryResult(array $result): bool
    {
        if ($result['success'] ?? false) {
            return false;
        }
        if (array_key_exists('retryable', $result)) {
            return (bool) $result['retryable'];
        }
        $status = $result['http_status'] ?? null;

        return in_array($status, [408, 429], true)
            || (is_numeric($status) && (int) $status >= 500)
            || ($status === null && filled($result['url'] ?? null));
    }

    private function applyResult(InsuranceAnalysis $analysis, array $result): void
    {
        (new \App\Services\Insurance\InsuranceProviderResultWriter($this->attemptId, $this->isReanalysis, $this->options))->apply($analysis, $result);
        InsuranceAnalysisAttempt::dispatchCompletion($analysis);
    }

    private function analysisSnapshot(InsuranceAnalysis $analysis): array
    {
        return [
            'attempt_id' => $this->attemptId,
            'is_reanalysis' => $this->isReanalysis,
            'reanalysis_options' => $this->options,

            'analysis_id' => $analysis->id,
            'batch_id' => $analysis->insurance_analysis_batch_id,
            'lead_id' => $analysis->lead_id,
            'company_id' => $analysis->company_id,

            'provider' => $analysis->provider,
            'product' => $analysis->product,

            'plan_key' => $analysis->plan_key,
            'multiple' => $analysis->multiple,

            'lease_start_date' => optional($analysis->lease_start_date)->toDateString(),
            'lease_end_date' => optional($analysis->lease_end_date)->toDateString(),
            'inhabited' => (bool) $analysis->inhabited,

            'rent_amount' => (float) $analysis->rent_amount,
            'charges_amount' => (float) $analysis->charges_amount,
            'total_monthly_amount' => (float) $analysis->total_monthly_amount,

            'payment_type' => $analysis->payment_type,
            'installments' => $analysis->installments,

            'captured_at' => now()->toDateTimeString(),
        ];
    }

    private function dispatchBatchCompletionCheck(InsuranceAnalysis $analysis): void
    {
        if (! $analysis->insurance_analysis_batch_id) {
            return;
        }

        InsuranceAnalysisAttempt::dispatchCompletion($analysis);
    }
}
