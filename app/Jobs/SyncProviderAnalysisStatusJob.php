<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysis;
use App\Services\Insurance\Providers\InsuranceProviderResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncProviderAnalysisStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Quantas vezes o Laravel pode tentar executar novamente esse job
     * caso ocorra falha temporária.
     */
    public int $tries = 3;

    /**
     * Tempo máximo de execução do job em segundos.
     */
    public int $timeout = 180;

    /**
     * Recebe o ID da análise específica.
     *
     * Exemplo:
     * analises_seguro.id = 15
     */
    public function __construct(
        public int $analysisId,
        public string $attemptId,
        public bool $isReanalysis = false
    ) {}

    /**
     * Executa a sincronização do status com a companhia.
     *
     * O InsuranceProviderResolver identifica qual provider usar:
     * - pottencial
     * - porto, futuramente
     * - tokio, futuramente
     * - outras companhias
     */
    public function handle(InsuranceProviderResolver $resolver): void
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            logger()->notice('Job de análise ignorado porque o módulo está desativado.', ['job' => static::class]);

            return;
        }

        /*
         * Carrega a análise com:
         * - lead.company: necessário para montar ou consultar dados vinculados à imobiliária;
         * - batch: necessário para recalcular o lote após atualizar essa análise.
         */
        $analysis = InsuranceAnalysis::with([
            'lead.company',
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'lead.locador',
            'lead.imobiliariaInformada',
            'batch',
        ])->findOrFail($this->analysisId);

        /*
         * Para consultar status na companhia, normalmente precisamos do quote_id
         * retornado na primeira solicitação da análise.
         */

        $isToo = strtolower((string) $analysis->provider) === 'too';


        if (!$isToo && !$analysis->quote_id) {
            $analysis->update([
                'status' => 'failed',
                'error_message' => 'Não foi possível sincronizar: quote_id não encontrado.',
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'sync_failed',
                'status' => 'failed',
                'message' => 'Não foi possível sincronizar o status porque a análise não possui quote_id.',
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        if($isToo && !$analysis->proposal_id){
            $analysis->update([
                'status' => 'failed',
                'error_message' => 'Não foi possível sincronizar: proposal_id não encontrado.',
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'sync_failed',
                'status' => 'failed',
                'message' => 'Não foi possível sincronizar o status porque a análise não possui proposal_id.',
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        /*
         * Registra no histórico que a sincronização começou.
         */
        $analysis->events()->create([
            'event_type' => 'sync_started',
            'status' => $analysis->status,
            'message' => "Iniciando sincronização de status com {$analysis->provider}.",
            'payload' => [
                'provider' => $analysis->provider,
                'quote_id' => $analysis->quote_id,
                'proposal_id' => $analysis->proposal_id,
                'manual_sync' => true,
            ],
        ]);

        try {
            /*
             * Resolve o provider correto.
             *
             * Exemplo:
             * provider = pottencial
             * classe usada = PottencialInsuranceProvider
             */
            $provider = $resolver->resolve($analysis->provider);

            /*
             * Chama a API da companhia para consultar o status atual.
             *
             * O provider deve retornar um array padronizado:
             *
             * [
             *     'success' => true,
             *     'status' => 200,
             *     'response' => [...]
             * ]
             */
            $result = $provider->getStatus($analysis);

            /*
             * Aplica o resultado recebido no banco.
             */

            if($isToo){
                $this->applyTooResult($analysis, $result);
                return;
            } 


            $this->applyResult($analysis, $result);
        } catch (\Throwable $e) {
            /*
             * Se der erro inesperado, não quebra o sistema inteiro.
             * Salva a falha na análise e registra no log.
             */
            $analysis->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'sync_failed',
                'status' => 'failed',
                'message' => $e->getMessage(),
                'payload' => [
                    'provider' => $analysis->provider,
                    'quote_id' => $analysis->quote_id,
                ],
            ]);

            Log::error('Erro ao sincronizar status da análise', [
                'analysis_id' => $analysis->id,
                'provider' => $analysis->provider,
                'quote_id' => $analysis->quote_id,
                'message' => $e->getMessage(),
            ]);

            $this->dispatchBatchCompletionCheck($analysis);
        }
    }

    /**
     * Atualiza a análise de acordo com a resposta da companhia.
     */
    private function applyResult(InsuranceAnalysis $analysis, array $result): void
    {
        $response = $result['response'] ?? [];

        $currentPayload = $analysis->response_payload ?? [];

        if (is_string($currentPayload)) {
            $currentPayload = json_decode($currentPayload, true) ?: [];
        }

        /*
         * Se a API retornou erro ou o provider sinalizou success false,
         * marcamos a análise como failed.
         */
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
                'event_type' => 'sync_failed',
                'status' => 'failed',
                'message' => 'Falha ao sincronizar status com a companhia.',
                'response' => $result,
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        /*
         * Status original vindo da companhia.
         *
         * Na Pottencial, por exemplo, pode vir:
         * - Approved
         * - Denied
         * - UnderAnalysis
         * - Pending
         */
        $providerStatus = $this->extractProviderStatus($response);

        /*
         * Converte o status da companhia para status interno do seu sistema.
         */
        $internalStatus = $this->mapInternalStatus($providerStatus);

        /*
         * Resultado final simplificado.
         * Esse campo é usado para dashboard e regras de negócio.
         */
        $resultStatus = $this->mapResultStatus($internalStatus);

        $analysis->update([
            /*
             * Status interno padronizado.
             */
            'status' => $internalStatus,

            /*
             * Resultado exibido no dashboard.
             */
            'result' => $resultStatus,

            /*
             * Status original retornado pela companhia.
             */
            'provider_status' => $providerStatus,

            /*
             * Mantém quote_id antigo se a resposta não trouxer outro.
             */
            'quote_id' => $this->extractQuoteIdFromResponse($response) ?? $analysis->quote_id,
            'quote_number' => $this->extractQuoteNumber($response) ?? $analysis->quote_number,
            'product_key' => $this->extractProductKey($response) ?? $analysis->product_key,

            /*
             * Planos e assistências retornados pela companhia.
             */
            'available_plans' => $response['availablePlans'] ?? $analysis->available_plans,
            'available_assistances' => $response['availableAssistances'] ?? $analysis->available_assistances,

            /*
             * Valores principais do orçamento, se vierem na resposta.
             */
            'premium_amount' => $this->extractPremiumAmount($response) ?? $analysis->premium_amount,
            'commercial_premium' => $this->extractCommercialPremium($response) ?? $analysis->commercial_premium,
            'gross_premium' => $this->extractGrossPremium($response) ?? $analysis->gross_premium,
            'iof' => $this->extractIof($response) ?? $analysis->iof,
            'insured_amount' => $this->extractInsuredAmount($response) ?? $analysis->insured_amount,

            /*
             * Salva a resposta completa para auditoria/debug.
             */
            'response_payload' => array_merge($currentPayload,[
                'sync_latest' => $response,
            ]),

            /*
             * Como essa sincronização trouxe uma resposta atualizada,
             * consideramos essa tentativa finalizada.
             */
            'finished_at' => now(),

            /*
             * Limpa mensagem de erro anterior, se a sincronização deu certo.
             */
            'error_message' => null,
        ]);

        $analysis->events()->create([
            'event_type' => 'status_synced',
            'status' => $internalStatus,
            'message' => "Status sincronizado com {$analysis->provider}.",
            'payload' => [
                'attempt_id' => $this->attemptId,
                'is_reanalysis' => $this->isReanalysis,
                'provider' => $analysis->provider,
                'quote_id' => $analysis->quote_id,
                'provider_status' => $providerStatus,
                'internal_status' => $internalStatus,
            ],
            'response' => $response,
        ]);

        /*
         * Depois de atualizar uma análise, verificamos se o lote inteiro
         * já pode ser encerrado. Quando encerrar, o CompleteInsuranceAnalysesBatchJob
         * vai disparar:
         *
         * - ApplyFinalAnalysisTagToLeadLoversJob
         * - SendAnalysisResultsEmailJob
         */
        $this->dispatchBatchCompletionCheck($analysis);
    }

    private function applyTooResult(InsuranceAnalysis $analysis, array $result): void
    {
        $response = $result['response'] ?? [];

        $currentPayload = $analysis->response_payload ?? [];

        if (is_string($currentPayload)) {
            $currentPayload = json_decode($currentPayload, true) ?: [];
        }

        if (!($result['success'] ?? false)) {
            $analysis->update([
                'status' => 'manual_review',
                'provider_status' => 'Erro ao verificar status manual da Too',
                'error_message' => data_get($response, 'message')
                    ?? data_get($result, 'error')
                    ?? 'Falha ao verificar status manual da Too.',
                'response_payload' => array_merge($currentPayload, [
                    'too_manual_sync_available' => true,
                    'too_status_check_stopped' => true,
                    'too_last_manual_check_at' => now()->toDateTimeString(),
                    'too_manual_status_latest' => $result,
                ]),
            ]);

            $analysis->events()->create([
                'event_type' => 'too_manual_sync_failed',
                'status' => 'manual_review',
                'message' => 'Falha ao verificar manualmente o status da Too.',
                'response' => $result,
            ]);

            return;
        }

        $providerStatus = data_get($response, 'status');
        $providerOriginalStatus = data_get($response, 'provider_original_status');
        $providerOriginalDescription = data_get($response, 'provider_original_description');

        /*
        * A Too ainda está analisando.
        * Mantém botão manual disponível e não finaliza a análise.
        */
        if (in_array($providerStatus, ['UnderAnalysis', 'Pending'], true)) {
            $analysis->update([
                'status' => 'manual_review',
                'result' => 'manual_review',
                'provider_status' => $providerOriginalDescription
                    ?? $providerOriginalStatus
                    ?? 'Em Análise de Crédito',
                'error_message' => null,
                'response_payload' => array_merge($currentPayload, [
                    'too_status_check_stopped' => true,
                    'too_manual_sync_available' => true,
                    'too_last_manual_check_at' => now()->toDateTimeString(),
                    'too_manual_status_latest' => $result,
                ]),
            ]);

            $analysis->events()->create([
                'event_type' => 'too_manual_status_synced',
                'status' => 'manual_review',
                'message' => 'A Too ainda retornou Em Análise de Crédito na verificação manual.',
                'payload' => [
                    'provider' => 'too',
                    'proposal_id' => $analysis->proposal_id,
                    'provider_status' => $providerStatus,
                    'provider_original_status' => $providerOriginalStatus,
                    'provider_original_description' => $providerOriginalDescription,
                ],
                'response' => $result,
            ]);

            return;
        }

        /*
        * A Too recusou/reprovou/cancelou/expirou.
        */
        if ($providerStatus === 'Denied') {
            $analysis->update([
                'status' => 'rejected',
                'result' => 'rejected',
                'provider_status' => $providerOriginalDescription
                    ?? $providerOriginalStatus
                    ?? 'Recusada',
                'error_message' => null,
                'response_payload' => array_merge($currentPayload, [
                    'too_status_check_stopped' => false,
                    'too_manual_sync_available' => false,
                    'too_last_manual_check_at' => now()->toDateTimeString(),
                    'too_manual_status_latest' => $result,
                ]),
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'too_manual_status_synced',
                'status' => 'rejected',
                'message' => 'Status da Too sincronizado manualmente: análise recusada.',
                'payload' => [
                    'provider' => 'too',
                    'proposal_id' => $analysis->proposal_id,
                    'provider_status' => $providerStatus,
                    'provider_original_status' => $providerOriginalStatus,
                    'provider_original_description' => $providerOriginalDescription,
                ],
                'response' => $result,
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        /*
        * A Too aprovou e o provider já tentou solicitar cotação.
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
                'available_plans' => data_get($response, 'paymentConditions') ?? $analysis->available_plans,
                'available_assistances' => data_get($response, 'coverages') ?? $analysis->available_assistances,
                'error_message' => null,
                'response_payload' => array_merge($currentPayload, [
                    'too_status_check_stopped' => false,
                    'too_manual_sync_available' => false,
                    'too_last_manual_check_at' => now()->toDateTimeString(),
                    'too_manual_status_latest' => $result,
                ]),
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'too_manual_status_synced',
                'status' => 'approved',
                'message' => 'Status da Too sincronizado manualmente: análise aprovada e cotação atualizada.',
                'payload' => [
                    'provider' => 'too',
                    'proposal_id' => $analysis->proposal_id,
                    'quote_id' => $quoteId,
                    'premium_amount' => $premiumAmount,
                    'provider_status' => $providerStatus,
                    'provider_original_status' => $providerOriginalStatus,
                    'provider_original_description' => $providerOriginalDescription,
                ],
                'response' => $result,
            ]);

            $this->dispatchBatchCompletionCheck($analysis);

            return;
        }

        /*
        * Status inesperado, mas resposta foi sucesso.
        * Mantém em análise manual.
        */
        $analysis->update([
            'status' => 'manual_review',
            'result' => 'manual_review',
            'provider_status' => $providerOriginalDescription
                ?? $providerOriginalStatus
                ?? $providerStatus
                ?? 'Status não reconhecido na Too',
            'response_payload' => array_merge($currentPayload, [
                'too_status_check_stopped' => true,
                'too_manual_sync_available' => true,
                'too_last_manual_check_at' => now()->toDateTimeString(),
                'too_manual_status_latest' => $result,
            ]),
        ]);

        $analysis->events()->create([
            'event_type' => 'too_manual_status_synced',
            'status' => 'manual_review',
            'message' => 'Status manual da Too sincronizado, mas ainda não houve decisão final.',
            'payload' => [
                'provider' => 'too',
                'proposal_id' => $analysis->proposal_id,
                'provider_status' => $providerStatus,
                'provider_original_status' => $providerOriginalStatus,
                'provider_original_description' => $providerOriginalDescription,
            ],
            'response' => $result,
        ]);
    }

    /**
     * Converte status da companhia para status interno do sistema.
     */
    private function mapInternalStatus(?string $providerStatus): string
    {
        return match ($providerStatus) {
            /*
             * Status da Pottencial.
             */
            'Approved' => 'approved',
            'Denied' => 'rejected',
            'UnderAnalysis', 'Pending' => 'manual_review',

            /*
             * Caso outras companhias retornem status já parecidos.
             */
            'approved' => 'approved',
            'rejected', 'denied' => 'rejected',
            'manual_review', 'under_analysis', 'pending' => 'manual_review',
            'failed' => 'failed',

            /*
             * Se não reconheceu, mas a chamada foi sucesso,
             * consideramos como cotado/recebido.
             */
            default => 'quoted',
        };
    }

    /**
     * Converte status interno para resultado final simplificado.
     */
    private function mapResultStatus(string $internalStatus): ?string
    {
        return match ($internalStatus) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'manual_review' => 'manual_review',
            default => null,
        };
    }

    /**
     * Tenta extrair o valor do orçamento/prêmio da resposta.
     *
     * Como cada companhia pode retornar nomes diferentes,
     * esse método tenta alguns caminhos comuns.
     */
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

    /**
     * Tenta extrair o valor segurado da resposta.
     */
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
     * Recalcula o status do lote após a sincronização.
     *
     * Esse job é importante porque seu sistema trabalha com várias companhias
     * ao mesmo tempo. Quando uma análise muda de status, o lote precisa ser
     * reavaliado.
     */
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
}
