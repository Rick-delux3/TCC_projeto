<?php

namespace App\Services\Insurance;

use App\Jobs\RunProviderAnalysisJob;
use App\Jobs\SyncProviderAnalysisStatusJob;
use App\Models\Lead;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

class PottencialAnalysisDiagnostic
{
    public function __construct(
        private InsuranceAnalysisService $analyses,
        private InsuranceProviderResolver $providers,
        private InsuranceApiHttpResult $httpResult,
    ) {}

    /** Execute inside a transaction that the caller must roll back, never commit. */
    public function run(Lead $lead, int $maxChecks, int $interval): array
    {
        if ($lead->getConnection()->transactionLevel() === 0) {
            throw new \LogicException('O diagnóstico exige uma transação temporária.');
        }

        $this->providers->resolve('pottencial');
        $analysis = $this->analyses->createPendingAnalysis($lead);
        $attemptId = (string) Str::uuid();
        $analysis->events()->where('event_type', 'created')->firstOrFail()->update([
            'payload' => ['attempt_id' => $attemptId, 'is_reanalysis' => false],
        ]);

        $steps = [$this->executeJob(new RunProviderAnalysisJob($analysis->id, $attemptId), 'create_and_request_analysis')];
        $analysis->refresh();

        if ($steps[0]['success'] && filled($analysis->quote_id)) {
            for ($check = 0; $check < $maxChecks; $check++) {
                if ($check > 0) {
                    Sleep::for($interval)->seconds();
                }

                $step = $this->executeJob(new SyncProviderAnalysisStatusJob($analysis->id, $attemptId), 'get_analysis_result');
                $steps[] = $step;
                $analysis->refresh();

                if (! $step['success'] || ProviderAnalysisStatus::isTerminal($analysis->status)) {
                    break;
                }
            }
        }

        $decisionReceived = in_array($analysis->status, ['approved', 'rejected'], true);
        $consulted = count($steps) > 1;
        $successful = $consulted && $decisionReceived && end($steps)['success'];
        $incomplete = $consulted && ! ProviderAnalysisStatus::isTerminal($analysis->status);

        return [
            'provider' => 'pottencial',
            'lead_id' => $lead->id,
            'success' => $successful,
            'incomplete' => $incomplete,
            'analysis' => [
                'attempt_id' => $attemptId,
                'quote_id' => $analysis->quote_id,
                'status' => $analysis->status,
                'result' => $analysis->result,
                'provider_status' => $analysis->provider_status,
                'premium_amount' => $analysis->premium_amount,
                'finished_at' => $analysis->finished_at?->toIso8601String(),
                'error' => $analysis->error_message,
            ],
            'steps' => $steps,
            'message' => $successful
                ? 'Fluxo concluído. Aprovação ou recusa é o resultado de negócio da companhia.'
                : ($incomplete
                    ? 'Limite de consultas atingido; a companhia ainda não retornou uma decisão final.'
                    : 'Não foi possível confirmar o fluxo completo. Confira as respostas de cada etapa.'),
        ];
    }

    private function executeJob(RunProviderAnalysisJob|SyncProviderAnalysisStatusJob $job, string $operation): array
    {
        $job->schedulePolling = false;
        $startedAt = hrtime(true);
        $executionError = null;

        try {
            $job->handle($this->providers);
        } catch (Throwable $exception) {
            $job->failed($exception);
            $executionError = 'A execução foi interrompida. Nenhuma repetição automática da criação foi realizada.';
        }

        $result = $job->providerResult ?? [];
        $status = $result['http_status'] ?? null;
        $headers = collect($result['headers'] ?? [])
            ->filter(fn (mixed $value, string $name): bool => in_array(strtolower($name), ['content-type', 'location', 'retry-after', 'x-request-id'], true))
            ->all();

        return [
            'operation' => $result['operation'] ?? $operation,
            'success' => ($result['success'] ?? false) && $executionError === null,
            'http_status' => $status,
            'http_label' => $this->httpResult->label($status),
            'http_description' => $this->httpResult->description($status),
            'endpoint' => $result['endpoint'] ?? null,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'headers' => $headers,
            'response' => $result['response'] ?? null,
            'raw_body' => $result['raw_body'] ?? null,
            'error' => $result['error'] ?? $executionError,
        ];
    }
}
