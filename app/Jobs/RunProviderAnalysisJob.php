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
use Illuminate\Bus\Batchable;

class RunProviderAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(
        public int $analysisId
    ) {}

    public function handle(InsuranceProviderResolver $resolver): void
    {
        $analysis = InsuranceAnalysis::with('lead.company', 'batch')->findOrFail($this->analysisId);

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
        $httpStatus = $result['http_status'] ?? null;
        $rawBody = $result['raw_body'] ?? null;


        $debugPayload = [
            'http_status' => $httpStatus,
            'success' => $result['success'] ?? false,
            'endpoint' => $result['endpoint'] ?? null,
            'url' => $result['url'] ?? null,
            'response' => $response,
            'raw_body' => $rawBody,
        ];

        if (!($result['success'] ?? false)) {
            $analysis->update([
                'status' => 'failed',
                'response_payload' => $debugPayload,
                'error_message' => is_array($response)
                    ? json_encode($response, JSON_UNESCAPED_UNICODE)
                    : (string) $rawBody,
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'failed',
                'status' => 'failed',
                'message' => 'Falha HTTP ao chamar {$analysis->provider}. Status: {$httpStatus}',
                'response' => $debugPayload,
            ]);

            return;
        }

        if (empty($response)) {
            $analysis->update([
                'status' => 'failed',
                'response_payload' => $debugPayload,
                'error_message' => "A API retornou HTTP {$httpStatus}, mas a resposta JSON veio vazia.",
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'empty_response',
                'status' => 'failed',
                'message' => "A API retornou HTTP {$httpStatus}, mas sem resposta JSON útil.",
                'response' => $debugPayload,
            ]);

            return;
        }

        $providerStatus = $response['status'] ?? null;
        $quoteId = $response['quoteId'] ?? null;

        if (!$providerStatus && !$quoteId) {
            $analysis->update([
                'status' => 'failed',
                'response_payload' => $debugPayload,
                'error_message' => 'Resposta recebida, mas sem status e sem quoteId.',
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'invalid_response',
                'status' => 'failed',
                'message' => 'Resposta recebida da companhia, mas sem status e sem quoteId.',
                'response' => $debugPayload,
            ]);

            return;
        }


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
            'quote_id' => $quoteId ?? $analysis->quote_id,

            'available_plans' => $response['availablePlans'] ?? $analysis->available_plans,
            'available_assistances' => $response['availableAssistances'] ?? $analysis->available_assistances,

            'premium_amount' => $this->extractPremiumAmount($response) ?? $analysis->premium_amount,
            'insured_amount' => $this->extractInsuredAmount($response) ?? $analysis->insured_amount,

            'response_payload' => $debugPayload,
            'finished_at' => now(),
        ]);

        $analysis->events()->create([
            'event_type' => $internalStatus,
            'status' => $internalStatus,
            'message' => "Resposta recebida da companhia {$analysis->provider}. HTTP {$httpStatus}.",
            'response' => $debugPayload,
        ]);

        if ($analysis->insurance_analysis_batch_id) {
            CompleteInsuranceAnalysesBatchJob::dispatch($analysis->insurance_analysis_batch_id);
        }
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