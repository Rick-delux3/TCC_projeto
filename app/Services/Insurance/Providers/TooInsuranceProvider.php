<?php

namespace App\Services\Insurance\Providers;

use App\Models\InsuranceAnalysis;
use App\Services\TooService;
use App\Services\Insurance\Payloads\TooRentalGuaranteePayloadBuilder;
use Illuminate\Support\Facades\Log;

class TooInsuranceProvider implements InsuranceProviderInterface
{
    public function __construct(
        private TooService $tooService,
        private TooRentalGuaranteePayloadBuilder $payloadBuilder
    ) {}

    public function name(): string
    {
        return 'too';
    }

    /**
     * Executa o fluxo de análise/cotação da Too.
     *
     * Fluxo:
     * 1. Cria ficha/proposta.
     * 2. Envia para análise de crédito.
     * 3. Consulta status.
     * 4. Se aprovado, solicita cotação.
     * 5. Retorna resposta padronizada para o RunProviderAnalysisJob.
     */
    public function requestAnalysis(InsuranceAnalysis $analysis): array
    {
        $analysis->loadMissing([
            'lead.company',
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'lead.locador',
            'lead.imobiliariaInformada',
        ]);

        $lead = $analysis->lead;

        if (!$lead) {
            return $this->failResult(
                message: 'Lead não encontrado para análise na Too.',
                step: 'load_lead'
            );
        }

        $cpf = $this->onlyNumbers($lead->cpf);

        if (!$cpf) {
            return $this->failResult(
                message: 'CPF do lead não encontrado para análise na Too.',
                step: 'validate_cpf'
            );
        }

        /*
         * ============================================================
         * 1. Monta e envia ficha/proposta.
         * ============================================================
         */
        if (!$lead->canBeSentToToo()) {
            return [
                'success' => true,
                'http_status' => null,
                'endpoint' => 'too_flow',
                'response' => [
                    'status' => 'UnderAnalysis',
                    'provider' => 'too',
                    'provider_original_status' => 'skipped',
                    'message' => 'Lead não enviado para Too: tipo_solicitante ou CPF incompatível com o fluxo da Too.',
                ],
                'raw_body' => null,
                'headers' => [],
            ];
        }
        
        $fichaPayload = $this->payloadBuilder->buildFichaPayload($analysis);

        $analysis->update([
            'request_payload' => [
                'provider' => 'too',
                'step' => 'ficha',
                'ficha_payload' => $fichaPayload,
            ],
        ]);

        $fichaResponse = $this->tooService->registerProposalFicha($fichaPayload);

        if (!$this->responseWasSuccessful($fichaResponse)) {
            return $this->failResult(
                message: 'Erro ao registrar ficha/proposta na Too.',
                step: 'register_ficha',
                responses: [
                    'ficha' => $fichaResponse,
                ]
            );
        }

        $fichaData = $fichaResponse['response'] ?? [];

        $numeroProposta = $this->extractFirstValue($fichaData, [
            'numeroProposta',
            'NumeroProposta',
            'numero_proposta',
            'proposalNumber',
            'proposal_number',
            'proposta',
        ]);

        $numeroFicha = $this->extractFirstValue($fichaData, [
            'numeroFicha',
            'NumeroFicha',
            'numero_ficha',
            'ficha',
            'fichaId',
            'idFicha',
        ]);

        /*
         * Em alguns ambientes, a Too pode retornar só um número.
         * Para sandbox, usamos fallback entre proposta/ficha.
         */
        $numeroProposta = $numeroProposta ?: $numeroFicha;
        $numeroFicha = $numeroFicha ?: $numeroProposta;

        if (!$numeroProposta || !$numeroFicha) {
            return $this->failResult(
                message: 'A Too registrou a ficha, mas não retornou numeroProposta/numeroFicha identificável.',
                step: 'extract_ficha_numbers',
                responses: [
                    'ficha' => $fichaResponse,
                ]
            );
        }

        $analysis->update([
            'proposal_id' => (string) $numeroProposta,
            'response_payload' => [
                'provider' => 'too',
                'ficha' => $fichaResponse,
                'numeroProposta' => $numeroProposta,
                'numeroFicha' => $numeroFicha,
            ],
        ]);

        /*
         * ============================================================
         * 2. Envia para análise de crédito.
         * ============================================================
         */
        $creditResponse = $this->tooService->submitCreditAnalysis(
            cpf: $cpf,
            numeroProposta: $numeroProposta
        );

        if (!$this->responseWasSuccessful($creditResponse)) {
            return $this->failResult(
                message: 'Erro ao enviar ficha para análise de crédito na Too.',
                step: 'submit_credit_analysis',
                responses: [
                    'ficha' => $fichaResponse,
                    'credit' => $creditResponse,
                ],
                extra: [
                    'numeroProposta' => $numeroProposta,
                    'numeroFicha' => $numeroFicha,
                ]
            );
        }

        /*
         * ============================================================
         * 3. Consulta status da proposta/análise.
         * ============================================================
         */
        $statusResponse = $this->tooService->getProposalStatus(
            cpf: $cpf,
            numeroProposta: $numeroProposta
        );

        if (!$this->responseWasSuccessful($statusResponse)) {
            return $this->failResult(
                message: 'Erro ao consultar status da proposta na Too.',
                step: 'get_proposal_status',
                responses: [
                    'ficha' => $fichaResponse,
                    'credit' => $creditResponse,
                    'status' => $statusResponse,
                ],
                extra: [
                    'numeroProposta' => $numeroProposta,
                    'numeroFicha' => $numeroFicha,
                ]
            );
        }

        $statusData = $statusResponse['response'] ?? [];

        $tooOriginalStatus = $this->extractProviderStatus($statusData);

        /*
         * Converte o status da Too para um status que seu
         * RunProviderAnalysisJob já entende.
         */
        $canonicalStatus = $this->canonicalStatus($tooOriginalStatus);

        /*
         * Se ainda está pendente, em análise ou recusado,
         * não solicitamos cotação agora.
         */
        if (in_array($canonicalStatus, ['Pending', 'UnderAnalysis', 'Denied'], true)) {
            return $this->successResult(
                status: $canonicalStatus,
                quoteId: null,
                premiumAmount: null,
                responses: [
                    'ficha' => $fichaResponse,
                    'credit' => $creditResponse,
                    'status' => $statusResponse,
                ],
                extra: [
                    'provider_original_status' => $tooOriginalStatus,
                    'numeroProposta' => $numeroProposta,
                    'numeroFicha' => $numeroFicha,
                ]
            );
        }

        /*
         * ============================================================
         * 4. Se aprovado, solicita cotação.
         * ============================================================
         */
        $quotePayload = $this->payloadBuilder->buildQuotePayload(
            analysis: $analysis,
            numeroFicha: $numeroFicha
        );

        $analysis->update([
            'request_payload' => [
                'provider' => 'too',
                'ficha_payload' => $fichaPayload,
                'quote_payload' => $quotePayload,
            ],
        ]);

        $quoteResponse = $this->tooService->requestQuote($quotePayload);

        if (!$this->responseWasSuccessful($quoteResponse)) {
            return $this->failResult(
                message: 'Erro ao solicitar cotação na Too.',
                step: 'request_quote',
                responses: [
                    'ficha' => $fichaResponse,
                    'credit' => $creditResponse,
                    'status' => $statusResponse,
                    'quote' => $quoteResponse,
                ],
                extra: [
                    'numeroProposta' => $numeroProposta,
                    'numeroFicha' => $numeroFicha,
                    'provider_original_status' => $tooOriginalStatus,
                ]
            );
        }

        $quoteData = $quoteResponse['response'] ?? [];

        $numeroCotacao = $this->extractFirstValue($quoteData, [
            'numeroCotacao',
            'NumeroCotacao',
            'numero_cotacao',
            'cotacao',
            'quoteId',
            'quote_id',
            'idCotacao',
        ]);

        $premiumAmount = $this->extractPremiumAmount($quoteData);

        $analysis->update([
            'quote_id' => $numeroCotacao ? (string) $numeroCotacao : $analysis->quote_id,
            'premium_amount' => $premiumAmount ?? $analysis->premium_amount,
        ]);

        return $this->successResult(
            status: 'Approved',
            quoteId: $numeroCotacao,
            premiumAmount: $premiumAmount,
            responses: [
                'ficha' => $fichaResponse,
                'credit' => $creditResponse,
                'status' => $statusResponse,
                'quote' => $quoteResponse,
            ],
            extra: [
                'provider_original_status' => $tooOriginalStatus,
                'numeroProposta' => $numeroProposta,
                'numeroFicha' => $numeroFicha,
                'numeroCotacao' => $numeroCotacao,
            ]
        );
    }

    /**
     * Consulta status posterior da Too.
     */
    public function getStatus(InsuranceAnalysis $analysis): array
    {
        $analysis->loadMissing('lead');

        $lead = $analysis->lead;

        if (!$lead || !$lead->cpf || !$analysis->proposal_id) {
            return $this->failResult(
                message: 'CPF ou número da proposta ausente para consultar status na Too.',
                step: 'get_status_validate'
            );
        }

        $response = $this->tooService->getProposalStatus(
            cpf: $this->onlyNumbers($lead->cpf),
            numeroProposta: $analysis->proposal_id
        );

        $statusData = $response['response'] ?? [];
        $tooOriginalStatus = $this->extractProviderStatus($statusData);
        $canonicalStatus = $this->canonicalStatus($tooOriginalStatus);

        return [
            'success' => $this->responseWasSuccessful($response),
            'http_status' => $response['http_status'] ?? null,
            'endpoint' => $response['endpoint'] ?? null,
            'url' => $response['url'] ?? null,
            'response' => [
                'status' => $canonicalStatus,
                'provider_original_status' => $tooOriginalStatus,
                'proposalId' => $analysis->proposal_id,
                'too' => [
                    'status' => $response,
                ],
            ],
            'raw_body' => $response['raw_body'] ?? null,
            'headers' => $response['headers'] ?? [],
        ];
    }

    private function responseWasSuccessful(array $response): bool
    {
        return (bool) ($response['success'] ?? false);
    }

    private function successResult(
        string $status,
        string|int|null $quoteId,
        ?float $premiumAmount,
        array $responses,
        array $extra = []
    ): array {
        return [
            'success' => true,
            'http_status' => $this->lastHttpStatus($responses),
            'endpoint' => 'too_flow',
            'url' => null,
            'response' => array_merge([
                'status' => $status,
                'quoteId' => $quoteId,
                'premiumAmount' => $premiumAmount,
                'provider' => 'too',
                'too' => $responses,
            ], $extra),
            'raw_body' => null,
            'headers' => [],
        ];
    }

    private function failResult(
        string $message,
        string $step,
        array $responses = [],
        array $extra = []
    ): array {
        Log::warning('Falha no fluxo da Too', [
            'step' => $step,
            'message' => $message,
            'extra' => $extra,
            'responses' => $responses,
        ]);

        return [
            'success' => false,
            'http_status' => $this->lastHttpStatus($responses),
            'endpoint' => 'too_flow',
            'url' => null,
            'response' => array_merge([
                'status' => 'Failed',
                'message' => $message,
                'step' => $step,
                'provider' => 'too',
                'too' => $responses,
            ], $extra),
            'raw_body' => null,
            'headers' => [],
            'error' => $message,
        ];
    }

    private function lastHttpStatus(array $responses): ?int
    {
        $lastStatus = null;

        foreach ($responses as $response) {
            if (is_array($response) && isset($response['http_status'])) {
                $lastStatus = (int) $response['http_status'];
            }
        }

        return $lastStatus;
    }

    private function extractProviderStatus(array $data): ?string
    {
        $status = $this->extractFirstValue($data, [
            'descricaoStatus',
            'descricao_status',
            'statusDescricao',
            'status_description',
            'situacao',
            'situacaoProposta',
            'statusProposta',
            'statusCredito',
            'parecer',
            'resultado',
            'status',
        ]);

        return $status !== null ? (string) $status : null;
    }

    private function canonicalStatus(?string $status): string
    {
        $normalized = $this->normalizeText($status);

        if (in_array($normalized, [
            'aprovado',
            'aprovada',
            'approved',
            'creditoaprovado',
            'propostaaprovada',
            'analiseaprovada',
        ], true)) {
            return 'Approved';
        }

        if (in_array($normalized, [
             'recusado',
            'recusada',
            'reprovado',
            'reprovada',
            'negado',
            'negada',
            'denied',
            'refused',
            'declined',
            'creditorecusado',
            'propostarecusada',
            'analiserecusada',
        ], true)) {
            return 'Denied';
        }

        if (in_array($normalized, [
            'emandamento',
            'emanalise',
            'analise',
            'analisecredito',
            'underanalysis',
            'underreview',
        ], true)) {
            return 'UnderAnalysis';
        }

        if (in_array($normalized, [
            'pendente',
            'pending',
            'aguardando',
            'emfila',
        ], true)) {
            return 'Pending';
        }

        return 'UnderAnalysis';
    }

    private function extractPremiumAmount(array $data): ?float
    {
        $value = $this->extractFirstValue($data, [
            'premiumAmount',
            'premioTotal',
            'valorPremioTotal',
            'valorPremio',
            'premio',
            'valorTotal',
            'total',
        ]);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(['R$', ' ', '.'], '', $value);
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function extractFirstValue(array $data, array $possibleKeys): mixed
    {
        $normalizedKeys = [];

        foreach ($possibleKeys as $key) {
            $normalizedKeys[] = $this->normalizeKey($key);
        }

        foreach ($data as $key => $value) {
            if (in_array($this->normalizeKey((string) $key), $normalizedKeys, true)) {
                return $value;
            }

            if (is_array($value)) {
                $found = $this->extractFirstValue($value, $possibleKeys);

                if ($found !== null && $found !== '') {
                    return $found;
                }
            }
        }

        return null;
    }

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($key));
    }

    private function normalizeText(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));

        $from = ['á', 'à', 'ã', 'â', 'é', 'ê', 'í', 'ó', 'ô', 'õ', 'ú', 'ç', ' '];
        $to = ['a', 'a', 'a', 'a', 'e', 'e', 'i', 'o', 'o', 'o', 'u', 'c', ''];

        return str_replace($from, $to, $text);
    }

    private function onlyNumbers(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}