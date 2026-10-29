<?php

namespace App\Services\Insurance;

use App\Jobs\RunInsuranceQuoteJob;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisRound;
use App\Models\InsuranceProviderRun;
use App\Models\InsuranceQuote;
use Illuminate\Support\Collection;

final class InsuranceQuoteCoordinator
{
    public function __construct(private InsuranceLeadSnapshot $snapshots) {}

    public function prepare(InsuranceAnalysis $analysis, string $attemptId, bool $isReanalysis): InsuranceProviderRun
    {
        return $analysis->getConnection()->transaction(function () use ($analysis, $attemptId, $isReanalysis): InsuranceProviderRun {
            $analysis = InsuranceAnalysis::query()->lockForUpdate()->findOrFail($analysis->id);
            InsuranceAnalysisAttempt::assertCurrent($analysis, $attemptId);
            $round = InsuranceAnalysisRound::query()->where('attempt_id', $attemptId)->first();
            $round ??= InsuranceAnalysisRound::query()->create([
                'attempt_id' => $attemptId,
                'insurance_analysis_batch_id' => $analysis->insurance_analysis_batch_id,
                'is_reanalysis' => $isReanalysis,
                'lead_snapshot' => $this->snapshots->capture($analysis->lead),
                'lease_start_date' => today()->toDateString(),
                'lease_end_date' => today()->addMonthsNoOverflow(30)->toDateString(),
            ]);
            $run = $analysis->providerRuns()->firstOrCreate(['insurance_analysis_round_id' => $round->id]);
            $run->load('round', 'analysis');
            if (! $run->scenarios()->exists()) {
                $this->createScenario($run, 'primary');
                $spouse = data_get($round->lead_snapshot, 'relations.conjuge');
                if (filled($spouse['cpf'] ?? null) && filled($spouse['nome'] ?? null)) {
                    $this->createScenario($run, 'spouse');
                }
            }

            return $run;
        });
    }

    public function findRun(InsuranceAnalysis $analysis, string $attemptId): ?InsuranceProviderRun
    {
        return $analysis->providerRuns()->whereHas('round', fn ($query) => $query->where('attempt_id', $attemptId))->first();
    }

    public function dispatchPending(InsuranceProviderRun $run): void
    {
        $connection = config('queue.default');
        $database = $run->getConnection()->getName();
        if (config("queue.connections.{$connection}.driver") !== 'database'
            || (config("queue.connections.{$connection}.connection") ?: $database) !== $database) {
            throw new \LogicException('As cotações exigem a fila database na mesma conexão da aplicação.');
        }
        $run->getConnection()->transaction(function () use ($run): void {
            $analysis = InsuranceAnalysis::query()->lockForUpdate()->findOrFail($run->insurance_analysis_id);
            InsuranceAnalysisAttempt::assertCurrent($analysis, $run->round->attempt_id);
            $quotes = InsuranceQuote::query()->whereHas('scenario', fn ($query) => $query->where('insurance_provider_run_id', $run->id))
                ->where('status', 'pending')->whereNull('dispatched_at')->lockForUpdate()->get();
            foreach ($quotes as $quote) {
                RunInsuranceQuoteJob::dispatch($quote->id, $run->round->attempt_id)->beforeCommit();
                $quote->update(['dispatched_at' => now()]);
            }
        });
    }

    public function resume(InsuranceProviderRun $run): void
    {
        $this->dispatchPending($run);
        $quotes = InsuranceQuote::query()->whereHas('scenario', fn ($query) => $query->where('insurance_provider_run_id', $run->id))
            ->whereNotNull('dispatched_at')->whereIn('status', ['processing', 'manual_review'])->get();
        foreach ($quotes as $quote) {
            RunInsuranceQuoteJob::dispatch($quote->id, $run->round->attempt_id, (int) $quote->poll_count)->afterCommit();
        }
    }

    public function consolidate(int $runId): void
    {
        $run = InsuranceProviderRun::query()->findOrFail($runId);
        $run->getConnection()->transaction(function () use ($run): void {
            $analysis = InsuranceAnalysis::query()->lockForUpdate()->findOrFail($run->insurance_analysis_id);
            InsuranceAnalysisAttempt::assertCurrent($analysis, $run->round->attempt_id);
            $run = InsuranceProviderRun::query()->lockForUpdate()->findOrFail($run->id);
            $run->load('round', 'analysis', 'scenarios.quotes');
            if ($run->finished_at) {
                return;
            }
            foreach ($run->scenarios as $scenario) {
                $scenario->update(['status' => $this->status($scenario->quotes)]);
            }
            $individuals = $run->scenarios->whereIn('kind', ['primary', 'spouse']);
            if ($individuals->count() === 2
                && $individuals->every(fn ($scenario) => $scenario->status === 'rejected')
                && ! $run->scenarios->contains('kind', 'joint')) {
                $this->createScenario($run, 'joint');
                $run->load('scenarios.quotes');
            }
            $quotes = $run->scenarios->flatMap(fn ($scenario) => $scenario->quotes);
            $status = $this->status($quotes);
            $finished = ProviderAnalysisStatus::isTerminal($status);
            $winner = $quotes->where('status', 'approved')->filter(fn ($quote) => (float) $quote->installment_amount > 0)
                ->sort(fn ($a, $b) => ((float) $a->installment_amount <=> (float) $b->installment_amount) ?: ($a->id <=> $b->id))->first();
            $representative = $winner ?? $quotes->where('status', 'approved')->first() ?? ($finished ? $quotes->first() : null);
            $run->update(['status' => $status, 'selected_quote_id' => $winner?->id, 'finished_at' => $finished ? now() : null]);
            $attributes = [
                'status' => $status, 'result' => ProviderAnalysisStatus::result($status),
                'finished_at' => $finished ? now() : null,
                'error_message' => $status === 'failed' ? 'Uma ou mais cotações falharam tecnicamente. Consulte as tentativas.' : null,
            ];
            if ($finished && $representative) {
                $attributes = array_merge($attributes, $representative->only([
                    'quote_id', 'quote_number', 'proposal_id', 'provider_status', 'product_key', 'plan_key',
                    'premium_amount', 'commercial_premium', 'gross_premium', 'iof', 'insured_amount',
                    'available_plans', 'available_assistances', 'payment_type', 'installments',
                    'request_payload', 'response_payload', 'lease_start_date', 'lease_end_date',
                ]));
                $attributes['response_payload'] = array_merge($attributes['response_payload'] ?? [], [
                    'quote_selection' => [
                        'provider_run_id' => $run->id, 'quote_id' => $winner?->id,
                        'installment_amount' => $winner?->installment_amount,
                        'installments' => $winner?->installments,
                        'payment_type' => $winner?->payment_type,
                    ],
                ]);
            }
            $analysis->forceFill($attributes)->save();
            if ($finished) {
                $analysis->events()->create([
                    'event_type' => 'analysis_completed', 'status' => $status,
                    'message' => 'Resultado consolidado das cotações da seguradora.',
                    'payload' => [
                        'attempt_id' => $run->round->attempt_id, 'is_reanalysis' => $run->round->is_reanalysis,
                        'provider_run_id' => $run->id, 'selected_quote_id' => $winner?->id,
                        'installment_amount' => $winner?->installment_amount,
                        'quote_count' => $quotes->count(),
                    ],
                ]);
                InsuranceAnalysisAttempt::dispatchCompletion($analysis);
            }
            $this->dispatchPending($run);
        });
    }

    private function status(Collection $quotes): string
    {
        if ($quotes->isEmpty() || $quotes->contains(fn ($quote) => ! ProviderAnalysisStatus::isTerminal($quote->status)
            || ($quote->status === 'approved' && $quote->payment_status === 'pending'))) {
            return 'processing';
        }
        if ($quotes->contains('status', 'approved')) {
            return 'approved';
        }

        return $quotes->every(fn ($quote) => $quote->status === 'rejected') ? 'rejected' : 'failed';
    }

    private function createScenario(InsuranceProviderRun $run, string $kind): void
    {
        $scenario = $run->scenarios()->create(['kind' => $kind]);
        $analysis = $run->analysis;
        $plans = $analysis->provider === 'pottencial' ? ['Basic', 'Complete', 'Traditional'] : ['default'];
        $registered = data_get($run->round->lead_snapshot, 'attributes.tipo_solicitante') === 'imobiliaria_cadastrada'
            && filled(data_get($run->round->lead_snapshot, 'attributes.company_id'));
        foreach ($plans as $plan) {
            $scenario->quotes()->create(array_merge($analysis->only([
                'insurance_analysis_batch_id', 'lead_id', 'company_id', 'provider', 'product',
                'rent_amount', 'charges_amount', 'total_monthly_amount', 'inhabited',
            ]), [
                'status' => 'pending', 'plan_key' => $plan, 'multiple' => 30, 'installments' => 30,
                'payment_type' => $registered ? 'Invoice' : 'Boleto',
                'lease_start_date' => $run->round->lease_start_date,
                'lease_end_date' => $run->round->lease_end_date,
            ]));
        }
    }
}
