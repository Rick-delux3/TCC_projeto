<?php

namespace App\Jobs;

use App\Exceptions\InvalidInsuranceAnalysisPayload;
use App\Exceptions\ObsoleteInsuranceAnalysisAttempt;
use App\Models\InsuranceQuote;
use App\Services\Insurance\InsuranceAnalysisAttempt;
use App\Services\Insurance\InsuranceLeadSnapshot;
use App\Services\Insurance\InsuranceQuoteCoordinator;
use App\Services\Insurance\InsuranceQuotePayment;
use App\Services\Insurance\InsuranceQuoteResult;
use App\Services\Insurance\ProviderAnalysisStatus;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunInsuranceQuoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $maxExceptions = 3;

    public int $timeout = 180;

    public array $backoff = [30, 120, 300];

    public function __construct(public int $quoteId, public string $attemptId, public int $generation = 0)
    {
        $this->onQueue('insurance-analyses');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("insurance-quote:{$this->quoteId}"))->shared()->releaseAfter(30)->expireAfter(210)];
    }

    public function handle(
        InsuranceProviderResolver $resolver,
        InsuranceQuoteCoordinator $coordinator,
        InsuranceLeadSnapshot $snapshots,
        InsuranceQuoteResult $writer,
        InsuranceQuotePayment $payments,
    ): void {
        if (! config('features.insurance_analysis.enabled', false)) {
            return;
        }
        $quote = InsuranceQuote::query()->find($this->quoteId);
        if (! $quote) {
            return;
        }
        try {
            InsuranceAnalysisAttempt::assertCurrent($quote, $this->attemptId);
            if ((int) $quote->poll_count !== $this->generation) {
                return;
            }
            if (ProviderAnalysisStatus::isTerminal($quote->status)
                && ($quote->status !== 'approved' || $quote->payment_status !== 'pending')) {
                $coordinator->consolidate($quote->scenario->insurance_provider_run_id);

                return;
            }
            $snapshots->attach($quote);
            $provider = $resolver->resolve($quote->provider);
            if (! ProviderAnalysisStatus::isTerminal($quote->status)) {
                $first = InsuranceAnalysisAttempt::run($quote, $this->attemptId, function () use ($quote): bool {
                    if ((int) $quote->poll_count !== $this->generation) {
                        throw new ObsoleteInsuranceAnalysisAttempt('Consulta já processada.');
                    }
                    $first = $quote->requested_at === null;
                    $quote->forceFill(['status' => 'processing', 'requested_at' => $quote->requested_at ?? now()])->save();

                    return $first;
                });
                if (! $first && blank($quote->quote_id) && blank($quote->proposal_id)) {
                    throw new InvalidInsuranceAnalysisPayload('A tentativa anterior não deixou identificador remoto. Verifique seu resultado antes de criar outra cotação.');
                }
                $result = $first || ($quote->isTooProvider() && $quote->poll_count === 0)
                    ? $provider->requestAnalysis($quote, $this->attemptId)
                    : $provider->getStatus($quote);
                if (! ($result['success'] ?? false) && ! $first && filled($quote->quote_id)
                    && (($result['http_status'] ?? 0) >= 500 || in_array($result['http_status'] ?? null, [null, 408, 429], true))) {
                    throw new \RuntimeException('Falha temporária ao consultar a cotação existente.');
                }
                $writer->apply($quote, $this->attemptId, $result);
            }
            if ($quote->status === 'approved' && $quote->payment_status === 'pending') {
                if (blank($quote->quote_id)) {
                    $writer->payment($quote, $this->attemptId, ['response' => [], 'selection' => null]);
                } else {
                    $writer->payment($quote, $this->attemptId, $payments->fetch($quote));
                }
            }
            $coordinator->consolidate($quote->scenario->insurance_provider_run_id);
            if (! ProviderAnalysisStatus::isTerminal($quote->status)
                && ! data_get($quote->providerResponsePayload(), 'too_status_check_stopped', false)) {
                InsuranceAnalysisAttempt::run($quote, $this->attemptId, function () use ($quote): void {
                    $quote->increment('poll_count');
                    $delay = max(1, (int) config("services.{$quote->provider}.status_check_delay_seconds", 30));
                    self::dispatch($quote->id, $this->attemptId, (int) $quote->poll_count)->delay(now()->addSeconds($delay))->beforeCommit();
                });
            }
        } catch (ObsoleteInsuranceAnalysisAttempt) {
            return;
        } catch (InvalidInsuranceAnalysisPayload|\DomainException|\InvalidArgumentException $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $quote = InsuranceQuote::query()->find($this->quoteId);
        if (! $quote) {
            return;
        }
        try {
            InsuranceAnalysisAttempt::run($quote, $this->attemptId, function () use ($quote, $exception): void {
                if ((int) $quote->poll_count !== $this->generation) {
                    return;
                }
                if ($quote->status === 'approved') {
                    $quote->forceFill(['payment_status' => 'failed'])->save();
                } elseif (! ProviderAnalysisStatus::isTerminal($quote->status)) {
                    $quote->forceFill([
                        'status' => 'failed', 'result' => null, 'finished_at' => now(),
                        'error_message' => $exception instanceof InvalidInsuranceAnalysisPayload ? $exception->getMessage() : 'Falha técnica ao executar a cotação.',
                    ])->save();
                }
                $quote->events()->create([
                    'event_type' => 'execution_failed', 'status' => $quote->status,
                    'message' => $exception instanceof InvalidInsuranceAnalysisPayload ? $exception->getMessage() : 'Falha técnica ao executar a cotação.',
                    'payload' => ['attempt_id' => $this->attemptId, 'exception' => $exception ? $exception::class : null],
                ]);
            });
            app(InsuranceQuoteCoordinator::class)->consolidate($quote->scenario->insurance_provider_run_id);
        } catch (ObsoleteInsuranceAnalysisAttempt) {
            return;
        }
    }
}
