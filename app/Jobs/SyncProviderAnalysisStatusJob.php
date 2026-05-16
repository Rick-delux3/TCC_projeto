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
     * insurance_analyses.id = 15
     */
    public function __construct(
        public int $analysisId
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
        /*
         * Carrega a análise com:
         * - lead.company: necessário para montar ou consultar dados vinculados à imobiliária;
         * - batch: necessário para recalcular o lote após atualizar essa análise.
         */
        $analysis = InsuranceAnalysis::with('lead.company', 'batch')
            ->findOrFail($this->analysisId);

        /*
         * Para consultar status na companhia, normalmente precisamos do quote_id
         * retornado na primeira solicitação da análise.
         */
        if (!$analysis->quote_id) {
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
        $providerStatus = $response['status'] ?? null;

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
            'quote_id' => $response['quoteId'] ?? $analysis->quote_id,

            /*
             * Planos e assistências retornados pela companhia.
             */
            'available_plans' => $response['availablePlans'] ?? $analysis->available_plans,
            'available_assistances' => $response['availableAssistances'] ?? $analysis->available_assistances,

            /*
             * Valores principais do orçamento, se vierem na resposta.
             */
            'premium_amount' => $this->extractPremiumAmount($response) ?? $analysis->premium_amount,
            'insured_amount' => $this->extractInsuredAmount($response) ?? $analysis->insured_amount,

            /*
             * Salva a resposta completa para auditoria/debug.
             */
            'response_payload' => $response,

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
        $value = $response['premiumAmount']
            ?? $response['premium']['total']
            ?? $response['availablePlans'][0]['premiumAmount']
            ?? $response['availablePlans'][0]['premium']['total']
            ?? null;

        return $value !== null ? (float) $value : null;
    }

    /**
     * Tenta extrair o valor segurado da resposta.
     */
    private function extractInsuredAmount(array $response): ?float
    {
        $value = $response['insuredAmount']
            ?? $response['availablePlans'][0]['insuredAmount']
            ?? null;

        return $value !== null ? (float) $value : null;
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
            $analysis->insurance_analysis_batch_id
        );
    }
}