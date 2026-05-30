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
        public int $analysisId
    ) {}

    public function handle(InsuranceProviderResolver $resolver): void
    {
        /*
         * Carrega lead.company porque o builder da Pottencial precisa:
         * - lead: dados do solicitante/PolicyHolder;
         * - company: CNPJ da imobiliária cadastrada, se houver PolicyOwner.
         */
        $analysis = InsuranceAnalysis::with([
            'lead.company',
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'lead.locador',
            'lead.imobiliariaInformada',
            'batch',
        ])
            ->findOrFail($this->analysisId);

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

            /*
             * Aqui o provider chama o service da companhia.
             * Para Pottencial, retorna:
             * success, http_status, endpoint, url, response, raw_body.
             */
            $result = $provider->requestAnalysis($analysis);

            Log::info('Resultado bruto recebido do provider', [
                'analysis_id' => $analysis->id,
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
                'event_type' => 'failed',
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);

            Log::error('Erro ao executar análise de provider', [
                'analysis_id' => $analysis->id,
                'provider' => $analysis->provider,
                'message' => $e->getMessage(),
            ]);

            /*
             * Mesmo falhando por exception, o lote precisa ser reavaliado.
             */
            $this->dispatchBatchCompletionCheck($analysis);
        }
    }

    private function applyResult(InsuranceAnalysis $analysis, array $result): void
    {
        $response = $result['response'] ?? [];
        $httpStatus = $result['http_status'] ?? null;
        $rawBody = $result['raw_body'] ?? null;
        $headers = $result['headers'] ?? [];

        /*
         * Payload salvo no banco para debug.
         */
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

         /**
     * Caso 1:
     * A requisição falhou de verdade.
     *
     * Exemplo:
     * - HTTP 400
     * - HTTP 401
     * - HTTP 422
     * - HTTP 500
     */
        if (!($result['success'] ?? false)) {
            $analysis->update([
                'status' => 'failed',
                'response_payload' => $debugPayload,
                'error_message' => $result['error']
                    ?? (is_array($response)
                        ? json_encode($response, JSON_UNESCAPED_UNICODE)
                        : (string) $rawBody),
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'failed',
                'status' => 'failed',
                'message' => "Falha HTTP ao chamar {$analysis->provider}. Status: {$httpStatus}",
                'response' => $debugPayload,
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

         /**
     * Tenta encontrar quoteId no JSON.
     */

        $providerStatus = $this->extractProviderStatus($response);
        $quoteId = $this->extractQuoteIdFromResponse($response);


        if (!$quoteId) {
            $quoteId = $this->extractQuoteIdFromHeaders($headers);
        }

         /**
     * Caso 2:
     * A API retornou sucesso HTTP, mas sem JSON útil.
     *
     * Exemplo atual:
     * HTTP 201
     * raw_body = "undefined"
     * response = []
     *
     * Isso NÃO deve virar "failed/ruim", porque a API aceitou a solicitação.
     * Vamos marcar como manual_review/em negociação.
     */
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
                'event_type' => 'created_without_body',
                'status' => 'manual_review',
                'message' => "A API retornou HTTP {$httpStatus}, mas sem JSON útil. A análise foi marcada como em negociação.",
                'response' => $debugPayload,
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        /*
         * Caso 3: veio JSON, mas sem os campos mínimos esperados.
         */
        

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

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        /*
         * Mapeia status da Pottencial para status interno do sistema.
         */
        $internalStatus = match ($providerStatus) {
            'Approved' => 'approved',
            'Denied' => 'rejected',
            'UnderAnalysis', 'Pending' => 'manual_review',

            /*
             * Se vier quoteId mas não vier status conhecido,
             * consideramos como cotado.
             */
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

        $analysis->events()->create([
            'event_type' => $internalStatus,
            'status' => $internalStatus,
            'message' => "Resposta recebida da companhia {$analysis->provider}. HTTP {$httpStatus}.",
            'response' => $debugPayload,
        ]);

        $this->dispatchBatchCompletionCheck($analysis);
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

    /**
     * Reavalia o lote depois que uma análise termina.
     *
     * Isso permite:
     * - fechar o batch;
     * - aplicar tag final no LeadLovers;
     * - enviar e-mail.
     */
    private function dispatchBatchCompletionCheck(InsuranceAnalysis $analysis): void
    {
        if (!$analysis->insurance_analysis_batch_id) {
            return;
        }

        CompleteInsuranceAnalysesBatchJob::dispatch(
            $analysis->insurance_analysis_batch_id
        );
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
}
