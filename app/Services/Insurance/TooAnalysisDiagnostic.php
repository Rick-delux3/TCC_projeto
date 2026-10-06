<?php

namespace App\Services\Insurance;

use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\SyncTooAnalysisStatusJob;
use App\Models\InsuranceAnalysis;
use App\Models\Lead;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use App\Services\Insurance\Providers\TooInsuranceProvider;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

class TooAnalysisDiagnostic
{
    public function __construct(
        private InsuranceProviderResolver $providers,
        private TooInsuranceProvider $provider,
        private InsuranceApiHttpResult $httpResult,
    ) {}

    /** Execute inside a transaction that the caller must roll back, never commit. */
    public function run(Lead $lead, int $maxChecks, int $interval): array
    {
        if ($lead->getConnection()->transactionLevel() === 0) {
            throw new \LogicException('O diagnóstico exige uma transação temporária.');
        }

        $this->providers->resolve('too');
        $attemptId = (string) Str::uuid();
        $analysis = InsuranceAnalysis::query()->create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'provider' => 'too',
            'product' => $lead->rentalGuaranteeProduct(),
            'status' => 'pending',
            'multiple' => 30,
            'lease_start_date' => now()->toDateString(),
            'lease_end_date' => now()->addMonthsNoOverflow(30)->toDateString(),
        ]);
        $analysis->events()->create([
            'event_type' => 'created',
            'status' => 'pending',
            'message' => 'Análise temporária para diagnóstico da Too.',
            'payload' => ['attempt_id' => $attemptId, 'is_reanalysis' => false],
        ]);

        $execution = $this->executeJob(new RunProviderAnalysisJob($analysis->id, $attemptId));
        $steps = $execution['steps'];
        $duration = $execution['duration_ms'];
        $analysis->refresh();

        for ($check = 1; $check < $maxChecks && $execution['success'] && ! ProviderAnalysisStatus::isTerminal($analysis->status); $check++) {
            Sleep::for($interval)->seconds();
            $execution = $this->executeJob(new SyncTooAnalysisStatusJob($analysis->id, $attemptId, attemptNumber: $check + 1));
            $steps = array_merge($steps, $execution['steps']);
            $duration += $execution['duration_ms'];
            $analysis->refresh();
        }

        $quoteConfirmed = filled($analysis->quote_id) && $analysis->premium_amount !== null && (float) $analysis->premium_amount > 0;
        $success = $execution['success'] && ($analysis->status === 'rejected' || ($analysis->status === 'approved' && $quoteConfirmed));
        $incomplete = $execution['success'] && ! ProviderAnalysisStatus::isTerminal($analysis->status);
        $error = $analysis->error_message ?? $execution['error'];
        if ($analysis->status === 'approved' && ! $quoteConfirmed) {
            $error = 'A Too aprovou o crédito, mas a cotação não retornou identificação e prêmio válidos.';
        }

        return [
            'provider' => 'too',
            'lead_id' => $lead->id,
            'success' => $success,
            'incomplete' => $incomplete,
            'duration_ms' => $duration,
            'analysis' => [
                'attempt_id' => $attemptId,
                'proposal_id' => $analysis->tooNumeroProposta(),
                'numero_ficha' => $analysis->tooNumeroFicha(),
                'quote_id' => $analysis->quote_id,
                'status' => $analysis->status,
                'result' => $analysis->result,
                'provider_status' => $analysis->provider_status,
                'premium_amount' => $analysis->premium_amount,
                'finished_at' => $analysis->finished_at?->toIso8601String(),
                'error' => $error,
            ],
            'steps' => $steps,
            'message' => $success
                ? ($analysis->status === 'rejected' ? 'Análise recusada pela Too; cotação não solicitada.' : 'Análise aprovada e cotação obtida na Too.')
                : ($incomplete ? 'Limite de consultas atingido; a Too ainda não retornou uma decisão final.' : 'Não foi possível confirmar o fluxo completo. Confira as respostas de cada etapa.'),
        ];
    }

    /** @return array{success: bool, duration_ms: int, error: ?string, steps: array} */
    private function executeJob(RunProviderAnalysisJob|SyncTooAnalysisStatusJob $job): array
    {
        $job->schedulePolling = false;
        $startedAt = hrtime(true);
        $executionError = null;

        try {
            if ($job instanceof RunProviderAnalysisJob) {
                $job->handle($this->providers);
            } else {
                $job->handle($this->provider);
            }
        } catch (Throwable $exception) {
            $job->failed($exception);
            $executionError = 'A execução foi interrompida. Nenhuma repetição automática de operações de criação foi realizada.';
        }

        $result = $job->providerResult ?? [];
        $steps = [];
        foreach (data_get($result, 'response.too', []) as $operation => $response) {
            $status = $response['http_status'] ?? null;
            $steps[] = [
                'operation' => $response['operation'] ?? $operation,
                'success' => $response['success'] ?? false,
                'http_status' => $status,
                'http_label' => $this->httpResult->label($status),
                'http_description' => $this->httpResult->description($status),
                'endpoint' => $response['endpoint'] ?? null,
                'headers' => collect($response['headers'] ?? [])
                    ->filter(fn (mixed $value, string $name): bool => in_array(strtolower($name), ['content-type', 'location', 'retry-after', 'x-request-id'], true))->all(),
                'response' => $response['response'] ?? null,
                'raw_body' => $response['raw_body'] ?? null,
                'error' => $response['error'] ?? null,
            ];
        }

        return [
            'success' => ($result['success'] ?? false) && $executionError === null,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'error' => $result['error'] ?? $executionError,
            'steps' => $steps,
        ];
    }
}
