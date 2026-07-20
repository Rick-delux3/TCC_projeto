<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysis;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunProviderAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(
        public int $analysisId,
        public string $attemptId,
        public bool $isReanalysis = false,
        public array $options = []
    ) {}

    public function handle(InsuranceProviderResolver $resolver): void
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

        try {
            $provider = $resolver->resolve($analysis->provider);
            
            if($this->isReanalysis) {
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

            Log::info('Resultado bruto recebido do provider', [
                'analysis_id' => $analysis->id,
                'attempt_id' => $this->attemptId,
                'is_reanalysis' => $this->isReanalysis,
                'provider' => $analysis->provider,
                'result' => $result,
            ]);

            $this->applyResult($analysis, $result);
        } catch (\Throwable $e) {
            $analysis->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => $this->isReanalysis ? 'reanalysis_failed' : 'failed',
                'status' => 'failed',
                'message' => $e->getMessage(),
                'payload' => $this->analysisSnapshot($analysis),
            ]);

            Log::error('Erro ao executar análise de provider', [
                'analysis_id' => $analysis->id,
                'attempt_id' => $this->attemptId,
                'is_reanalysis' => $this->isReanalysis,
                'provider' => $analysis->provider,
                'message' => $e->getMessage(),
            ]);

            $this->dispatchBatchCompletionCheck($analysis);
        }
    }

    private function applyResult(InsuranceAnalysis $analysis, array $result): void
    {
        $isToo = strtolower((string) $analysis->provider) === 'too';
        $response = $result['response'] ?? [];
        $httpStatus = $result['http_status'] ?? null;
        $rawBody = $result['raw_body'] ?? null;
        $headers = $result['headers'] ?? [];

        $debugPayload = [
            'http_status' => $httpStatus,
            'success' => $result['success'] ?? false,
            'endpoint' => $result['endpoint'] ?? null,
            'url' => $result['url'] ?? null,
            'response' => $response,
            'raw_body' => $rawBody,
            'headers' => $headers,
            'error' => $result['error'] ?? null,
        ];

        if (!($result['success'] ?? false)) {
            $currentPayload = $this->payloadAsArray(
            $analysis->response_payload
        );

            $responsePayload = $isToo
                ? array_merge($currentPayload, [
                    'too_last_error' => $debugPayload,
                    'too_reanalysis_failed_at' => $this->isReanalysis
                        ? now()->toDateTimeString()
                        : null,
                ])
                : $debugPayload;

            $analysis->update([
                'status' => 'failed',
                'response_payload' => $responsePayload,
                'error_message' => $result['error']
                    ?? (
                        is_array($response)
                            ? json_encode(
                                $response,
                                JSON_UNESCAPED_UNICODE
                            )
                            : (string) $rawBody
                    ),
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => $this->isReanalysis
                    ? 'reanalysis_failed'
                    : 'failed',
                'status' => 'failed',
                'message' => "Falha HTTP ao chamar {$analysis->provider}. Status: {$httpStatus}",
                'payload' => $this->analysisSnapshot($analysis),
                'response' => $debugPayload,
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        if($isToo) {
            $this->applyTooResult($analysis, $result, $debugPayload);

            return;
        }

        $providerStatus = $this->extractProviderStatus($response);
        $quoteId = $this->extractQuoteIdFromResponse($response);

        if (!$quoteId) {
            $quoteId = $this->extractQuoteIdFromHeaders($headers);
        }

        if (empty($response)) {
            $analysis->update([
                'status' => 'manual_review',
                'result' => 'manual_review',
                'provider_status' => 'CreatedWithoutBody',
                'quote_id' => $quoteId,

                'response_payload' => $debugPayload,
                'error_message' => 'A API retornou sucesso HTTP, mas sem corpo JSON útil. Verifique headers e consulta de status.',
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => $this->isReanalysis ? 'reanalysis_created_without_body' : 'created_without_body',
                'status' => 'manual_review',
                'message' => "A API retornou HTTP {$httpStatus}, mas sem JSON útil. A análise foi marcada como em negociação.",
                'payload' => $this->analysisSnapshot($analysis),
                'response' => $debugPayload,
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        if (!$providerStatus && !$quoteId) {
            $analysis->update([
                'status' => 'failed',
                'response_payload' => $debugPayload,
                'error_message' => 'Resposta recebida, mas sem status e sem quoteId.',
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => $this->isReanalysis ? 'reanalysis_invalid_response' : 'invalid_response',
                'status' => 'failed',
                'message' => 'Resposta recebida da companhia, mas sem status e sem quoteId.',
                'payload' => $this->analysisSnapshot($analysis),
                'response' => $debugPayload,
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

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

            'result' => in_array($internalStatus, ['approved', 'rejected', 'manual_review'], true)
                ? $internalStatus
                : null,

            'provider_status' => $providerStatus,
            'quote_id' => $quoteId ?? $analysis->quote_id,
            'quote_number' => $this->extractQuoteNumber($response) ?? $analysis->quote_number,
            'product_key' => $this->extractProductKey($response) ?? $analysis->product_key,

            'available_plans' => $response['availablePlans'] ?? $analysis->available_plans,
            'available_assistances' => $response['availableAssistances'] ?? $analysis->available_assistances,

            'premium_amount' => $this->extractPremiumAmount($response) ?? $analysis->premium_amount,
            'commercial_premium' => $this->extractCommercialPremium($response) ?? $analysis->commercial_premium,
            'gross_premium' => $this->extractGrossPremium($response) ?? $analysis->gross_premium,
            'iof' => $this->extractIof($response) ?? $analysis->iof,
            'insured_amount' => $this->extractInsuredAmount($response) ?? $analysis->insured_amount,

            'response_payload' => $debugPayload,
            'error_message' => null,
            'finished_at' => now(),
        ]);

        $analysis->refresh();

        $analysis->events()->create([
            'event_type' => $this->isReanalysis ? 'reanalysis_completed' : 'analysis_completed',
            'status' => $internalStatus,
            'message' => $this->isReanalysis
                ? "Reanálise concluída para companhia {$analysis->provider}. HTTP {$httpStatus}."
                : "Análise concluída para companhia {$analysis->provider}. HTTP {$httpStatus}.",
            'payload' => $this->analysisSnapshot($analysis),
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
                'debug' => $debugPayload,
            ],
        ]);

        $this->dispatchBatchCompletionCheck($analysis);
    }

    private function applyTooResult(InsuranceAnalysis $analysis, array $result, array $debugPayload): void
    {
        $response = $result['response'] ?? [];

        $currentPayload = $this->payloadAsArray($analysis->response_payload);

        $resultPayloadKey = $this->isReanalysis ? 'too_reanalysis_result' : 'too_initial_result';

        $providerStatus = data_get($response, 'status');
        $tooInternalDecision = data_get($response, 'too_internal_decision');
        $providerOriginalStatus = data_get($response, 'provider_original_status');
        $providerOriginalDescription = data_get($response, 'provider_original_description');

        /*
        * A Too ainda está analisando o crédito.
        *
        * Importante:
        * - não marcar como manual_review ainda;
        * - não preencher finished_at;
        * - não fechar o lote;
        * - não disparar e-mail/tag final ainda.
        *
        * O SyncTooAnalysisStatusJob continuará verificando automaticamente.
        */
        if (
            in_array($providerStatus, ['UnderAnalysis', 'Pending'], true)
            && $tooInternalDecision !== 'PreApproved'
        ) {
            $analysis->update([
                'status' => 'processing',
                'result' => null,
                'provider_status' => $providerOriginalDescription
                    ?? $providerOriginalStatus
                    ?? 'Em Análise de Crédito',
                'response_payload' => array_merge($currentPayload, [
                    $resultPayloadKey => $debugPayload,
                    'too_status_check_stopped' => false,
                    'too_manual_sync_available' => false,
                    'too_last_auto_check_at' => now()->toDateTimeString(),
                ]),
                'error_message' => null,
                'finished_at' => null,
            ]);

            $analysis->events()->create([
                'event_type' => 'too_waiting_credit_analysis',
                'status' => 'processing',
                'message' => 'A Too recebeu a análise e ainda está processando o crédito. A verificação automática continuará em segundo plano.',
                'payload' => $this->analysisSnapshot($analysis),
                'response' => $debugPayload,
            ]);

            return;
        }

        /*
        * Status 16: pré-aprovado.
        *
        * Como a biometria não será implementada agora,
        * paramos como análise manual e liberamos consulta manual.
        */
        if ($tooInternalDecision === 'PreApproved') {
            $analysis->update([
                'status' => 'manual_review',
                'result' => 'manual_review',
                'provider_status' => $providerOriginalDescription
                    ?? $providerOriginalStatus
                    ?? 'Análise pré-aprovada',
                'response_payload' => array_merge($currentPayload, [
                    $resultPayloadKey => $debugPayload,
                    'too_status_check_stopped' => true,
                    'too_manual_sync_available' => true,
                    'too_status_check_stopped_at' => now()->toDateTimeString(),
                ]),
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => $this->isReanalysis ? 'reanalysis_completed' : 'analysis_completed',
                'status' => 'manual_review',
                'message' => 'A Too retornou análise pré-aprovada. Como a biometria não será tratada agora, a análise ficou em revisão manual.',
                'payload' => $this->analysisSnapshot($analysis),
                'response' => $debugPayload,
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        /*
        * Too recusou/reprovou/cancelou/expirou.
        */
        if ($providerStatus === 'Denied') {
            $analysis->update([
                'status' => 'rejected',
                'result' => 'rejected',
                'provider_status' => $providerOriginalDescription
                    ?? $providerOriginalStatus
                    ?? 'Recusada',
                'response_payload' => array_merge($currentPayload, [
                    $resultPayloadKey => $debugPayload,
                    'too_status_check_stopped' => false,
                    'too_manual_sync_available' => false,
                ]),
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => $this->isReanalysis ? 'reanalysis_completed' : 'analysis_completed',
                'status' => 'rejected',
                'message' => 'Análise da Too concluída como recusada.',
                'payload' => $this->analysisSnapshot($analysis),
                'response' => $debugPayload,
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        /*
        * Too aprovou e o provider já solicitou cotação.
        */
        if ($providerStatus === 'Approved') {
            $quoteId = data_get($response, 'quoteId');
            $premiumAmount = data_get($response, 'premiumAmount');

            $analysis->update([
                'status' => 'approved',
                'result' => 'approved',
                'provider_status' => $providerOriginalDescription
                    ?? $providerOriginalStatus
                    ?? 'Aprovada',
                'quote_id' => $quoteId ? (string) $quoteId : $analysis->quote_id,
                'quote_number' => $quoteId ? (string) $quoteId : $analysis->quote_number,

                'premium_amount' => $premiumAmount ?? $analysis->premium_amount,
                'commercial_premium' => $premiumAmount ?? $analysis->commercial_premium,

                'available_plans' => data_get($response, 'paymentConditions')
                    ?? $analysis->available_plans,

                'available_assistances' => data_get($response, 'coverages')
                    ?? $analysis->available_assistances,

                'response_payload' => array_merge($currentPayload, [
                    $resultPayloadKey => $debugPayload,
                    'too_status_check_stopped' => false,
                    'too_manual_sync_available' => false,
                    'too_quote_result' => [
                        'quote_id' => $quoteId,
                        'premium_amount' => $premiumAmount,
                        'payment_conditions' => data_get($response, 'paymentConditions'),
                        'coverages' => data_get($response, 'coverages'),
                    ],
                ]),
                'error_message' => null,
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => $this->isReanalysis ? 'reanalysis_completed' : 'analysis_completed',
                'status' => 'approved',
                'message' => 'Análise da Too aprovada e cotação registrada.',
                'payload' => $this->analysisSnapshot($analysis),
                'response' => [
                    'provider' => $analysis->provider,
                    'provider_status' => $analysis->provider_status,
                    'quote_id' => $analysis->quote_id,
                    'quote_number' => $analysis->quote_number,
                    'premium_amount' => $analysis->premium_amount,
                    'commercial_premium' => $analysis->commercial_premium,
                    'available_plans' => $analysis->available_plans,
                    'available_assistances' => $analysis->available_assistances,
                    'debug' => $debugPayload,
                ],
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        /*
        * Qualquer resposta inesperada da Too.
        * Mantém em análise manual para não perder o controle.
        */
        $analysis->update([
            'status' => 'manual_review',
            'result' => 'manual_review',
            'provider_status' => $providerOriginalDescription
                ?? $providerOriginalStatus
                ?? $providerStatus
                ?? 'Status não reconhecido na Too',
            'response_payload' => array_merge($currentPayload, [
                $resultPayloadKey => $debugPayload,
                'too_status_check_stopped' => true,
                'too_manual_sync_available' => true,
            ]),
            'error_message' => null,
            'finished_at' => now(),
        ]);

        $analysis->events()->create([
            'event_type' => $this->isReanalysis ? 'reanalysis_completed' : 'analysis_completed',
            'status' => 'manual_review',
            'message' => 'A Too retornou um status não finalizado ou não reconhecido. A análise ficou em revisão manual.',
            'payload' => $this->analysisSnapshot($analysis),
            'response' => $debugPayload,
        ]);

        $this->dispatchBatchCompletionCheck($analysis);
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
        if (!$analysis->insurance_analysis_batch_id) {
            return;
        }

        CompleteInsuranceAnalysesBatchJob::dispatch(
            batchId: $analysis->insurance_analysis_batch_id,
            attemptId: $this->attemptId,
            isReanalysis: $this->isReanalysis
        );
    }

    private function extractPremiumAmount(array $response): ?float
    {
        $value = $this->firstValue($response, [
            'premiumAmount',
            'premium.total',
            'quote.premiumAmount',
            'quote.premium.total',
            'data.premiumAmount',
            'data.premium.total',
            'availablePlans.0.premiumAmount',
            'availablePlans.0.premium.total',
        ]);

        return $value !== null ? (float) $value : null;
    }

    private function extractProviderStatus(array $response): ?string
    {
        $value = $this->firstValue($response, [
            'status',
            'quote.status',
            'data.status',

            'provider_original_status',
            'situacao',
            'situacaoProposta',
            'statusProposta',
            'statusCredito',
            'resultado',
            'parecer',

            'too.status.response.status',
            'too.status.response.situacao',
            'too.status.response.situacaoProposta',
            'too.status.response.statusProposta',
        ]);

        return $value !== null ? (string) $value : null;
    }

    private function extractQuoteIdFromResponse(array $response): ?string
    {
        $value = $this->firstValue($response, [
            'quoteId',
            'quote_id',
            'id',
            'quote.quoteId',
            'quote.id',
            'data.quoteId',
            'data.id',
        ]);

        return $value !== null ? (string) $value : null;
    }

    private function extractQuoteNumber(array $response): ?string
    {
        $value = $this->firstValue($response, [
            'quoteNumber',
            'quote_number',
            'number',
            'quote.quoteNumber',
            'quote.number',
            'data.quoteNumber',
            'data.number',
        ]);

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    private function extractProductKey(array $response): ?string
    {
        $value = $this->firstValue($response, [
            'productKey',
            'product_key',
            'quote.productKey',
            'data.productKey',
            'availablePlans.0.productKey',
        ]);

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    private function extractCommercialPremium(array $response): ?float
    {
        $value = $this->firstValue($response, [
            'commercialPremium',
            'commercial_premium',
            'quote.commercialPremium',
            'data.commercialPremium',
            'availablePlans.0.commercialPremium',
        ]);

        return $value !== null && $value !== '' ? (float) $value : null;
    }

    private function extractGrossPremium(array $response): ?float
    {
        $value = $this->firstValue($response, [
            'grossPremium',
            'gross_premium',
            'quote.grossPremium',
            'data.grossPremium',
            'availablePlans.0.grossPremium',
        ]);

        return $value !== null && $value !== '' ? (float) $value : null;
    }

    private function extractIof(array $response): ?float
    {
        $value = $this->firstValue($response, [
            'iof',
            'IOF',
            'quote.iof',
            'data.iof',
            'availablePlans.0.iof',
        ]);

        return $value !== null && $value !== '' ? (float) $value : null;
    }

    private function extractInsuredAmount(array $response): ?float
    {
        $value = $this->firstValue($response, [
            'insuredAmount',
            'quote.insuredAmount',
            'data.insuredAmount',
            'availablePlans.0.insuredAmount',
        ]);

        return $value !== null ? (float) $value : null;
    }

    private function firstValue(array $response, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($response, $path);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractQuoteIdFromHeaders(array $headers): ?string
    {
        /**
         * Normalmente headers vêm assim:
         *
         * [
         *   "Location" => [
         *      "https://api.../quotes/123"
         *   ]
         * ]
         *
         * ou com letras minúsculas dependendo do client.
         */
        $location = $headers['Location'][0]
            ?? $headers['location'][0]
            ?? null;

        if (!$location) {
            return null;
        }

        /**
         * Tenta pegar o último trecho da URL.
         *
         * Exemplo:
         * /quotes/abc-123
         * retorna abc-123
         */
        $parts = explode('/', trim($location, '/'));

        return end($parts) ?: null;
    }

    private function payloadAsArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload)) {
            return json_decode($payload, true) ?: [];
        }

        return [];
    }
}
