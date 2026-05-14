<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysis;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunProviderAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(
        public int $analysisId
    ) {}

    public function handle(InsuranceProviderResolver $resolver): void
    {
        $analysis = InsuranceAnalysis::with('lead', 'batch')->findOrFail($this->analysisId);

        $analysis->update([
            'status' => 'processing',
            'requested_at' => now(),
            'error_message' => null,
        ]);

        $analysis->events()->create([
            'event_type' => 'sent_to_api',
            'status' => 'processing',
            'message' => "Enviando análise para {$analysis->provider}.",
        ]);

        try {
            $provider = $resolver->resolve($analysis->provider);

            $result = $provider->requestAnalysis($analysis);

            $this->applyResult($analysis, $result);
        } catch (\Throwable $e) {
            $analysis->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'failed',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);

            Log::error('Erro ao executar análise de provider', [
                'analysis_id' => $analysis->id,
                'provider' => $analysis->provider,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function applyResult(InsuranceAnalysis $analysis, array $result): void
    {
        $response = $result['response'] ?? [];

        if (!($result['success'] ?? false)) {
            $analysis->update([
                'status' => 'failed',
                'response_payload' => $response,
                'error_message' => is_array($response)
                    ? json_encode($response, JSON_UNESCAPED_UNICODE)
                    : (string) $response,
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'failed',
                'status' => 'failed',
                'message' => 'Falha na resposta da companhia.',
                'response' => $result,
            ]);

            return;
        }

        $providerStatus = $response['status'] ?? null;

        $internalStatus = match ($providerStatus) {
            'Approved' => 'approved',
            'Denied' => 'rejected',
            'UnderAnalysis', 'Pending' => 'manual_review',
            default => 'quoted',
        };

        $analysis->update([
            'status' => $internalStatus,
            'result' => in_array($internalStatus, ['approved', 'rejected', 'manual_review'])
                ? $internalStatus
                : null,

            'provider_status' => $providerStatus,

            'quote_id' => $response['quoteId'] ?? $analysis->quote_id,

            'available_plans' => $response['availablePlans'] ?? $analysis->available_plans,
            'available_assistances' => $response['availableAssistances'] ?? $analysis->available_assistances,

            'premium_amount' => $this->extractPremiumAmount($response) ?? $analysis->premium_amount,
            'insured_amount' => $this->extractInsuredAmount($response) ?? $analysis->insured_amount,

            'response_payload' => $response,
            'finished_at' => now(),
        ]);

        $analysis->events()->create([
            'event_type' => $internalStatus,
            'status' => $internalStatus,
            'message' => "Resposta recebida da companhia {$analysis->provider}.",
            'response' => $response,
        ]);
    }

    private function extractPremiumAmount(array $response): ?float
    {
        return $response['premiumAmount']
            ?? $response['premium']['total']
            ?? $response['availablePlans'][0]['premiumAmount']
            ?? $response['availablePlans'][0]['premium']['total']
            ?? null;
    }

    private function extractInsuredAmount(array $response): ?float
    {
        return $response['insuredAmount']
            ?? $response['availablePlans'][0]['insuredAmount']
            ?? null;
    }
}