<?php

namespace App\Services\Insurance\Providers;

use App\Models\InsuranceAnalysis;
use App\Services\TooService;
use App\Services\Insurance\Payloads\TooRentalGuaranteePayloadBuilder;
use Illuminate\Support\Facades\Log;
use App\Jobs\SyncTooAnalysisStatusJob;

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
     * Executa o fluxo inicial da Too:
     *
     * 1. Registra ficha/proposta.
     * 2. Envia para análise de crédito.
     * 3. Consulta status.
     * 4. Se status 8, solicita cotação.
     * 5. Se status 5 ou 16, mantém em análise/manual_review.
     */
    public function requestAnalysis(InsuranceAnalysis $analysis, string $attemptId): array
    {
        $this->loadTooRelations($analysis);

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

        if (!$lead->canBeSentToToo()) {
            return $this->successResult(
                status: 'UnderAnalysis',
                quoteId: null,
                premiumAmount: null,
                responses: [],
                extra: [
                    'provider_original_status' => 'skipped',
                    'message' => 'Lead não enviado para Too: tipo_solicitante ou CPF incompatível com o fluxo da Too.',
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | 1. Registra ficha/proposta
        |--------------------------------------------------------------------------
        */
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
         * Em homologação, a Too pode retornar apenas numeroFicha.
         * Nesse fluxo, usamos o mesmo número como proposta/ficha.
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
                'too_analysis_attempt_id' => $attemptId,
                'too_is_reanalysis' => false,
            ],


        ]);

        /*
        |--------------------------------------------------------------------------
        | 2. Envia para análise de crédito
        |--------------------------------------------------------------------------
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
        |--------------------------------------------------------------------------
        | 3. Consulta status da proposta
        |--------------------------------------------------------------------------
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

        return $this->handleStatusAndMaybeQuote(
            analysis: $analysis,
            statusResponse: $statusResponse,
            baseResponses: [
                'ficha' => $fichaResponse,
                'credit' => $creditResponse,
                'status' => $statusResponse,
            ],
            baseExtra: [
                'attempt_id' => $attemptId,
                'is_reanalysis' => false,
                'numeroProposta' => $numeroProposta,
                'numeroFicha' => $numeroFicha,
            ],
            scheduleNextCheck: true,
            attemptId: $attemptId,
            isReanalysis: false
        );
    }

    public function requestReanalysis(
        InsuranceAnalysis $analysis,
        string $attemptId,
        array $options = [],
    ): array {
        $this->loadTooRelations($analysis);

        $lead = $analysis->lead;

        if(!$lead) {
            return $this->failResult(
                message: 'Lead não encontrado para reanálise na Too',
                step: 'too_reanalysis_load_lead'
            );
        }

        $cpf = $this->onlyNumbers($lead->cpf);

        if(!$cpf){
            return $this->failResult(
                message: 'CPF do lead não encontrado para reanálise na Too.',
                step: 'too_reanalysis_validate_cpf'
            );
        }

        $numeroProposta = $analysis->tooNumeroProposta();
        $numeroFicha = $analysis->tooNumeroFicha();

        if (!$numeroProposta || !$numeroFicha) {
            return $this->failResult(
                message: 'Número da proposta/ficha ausente para reanálise na Too.',
                step: 'too_reanalysis_missing_numbers',
                extra: [
                    'proposal_id' => $analysis->proposal_id,
                    'numeroProposta' => $numeroProposta,
                    'numeroFicha' => $numeroFicha,
                ]
            );
        }

        $statusBeforeResponse = $this->tooService->getProposalStatus(
            cpf: $cpf,
            numeroProposta: $numeroProposta
        );

        if (!$this->responseWasSuccessful($statusBeforeResponse)) {
            return $this->failResult(
                message: 'Erro ao consultar status antes da reanálise na Too.',
                step: 'too_reanalysis_status_before',
                responses: [
                    'status_before' => $statusBeforeResponse,
                ]
            );
        }

        $statusBeforeInfo = $this->tooCreditDecision($statusBeforeResponse['response'] ?? []);
        $statusCode = $statusBeforeInfo['status_code'];
        $blockedStatus = [9, 10, 12];

        if ($statusCode === null || in_array($statusCode, $blockedStatus, true)) {
            return $this->failResult(
                message: 'A proposta Too não está em status permitido para reanálise.',
                step: 'too_reanalysis_status_not_allowed',
                responses: [
                    'status_before' => $statusBeforeResponse,
                ],
                extra: [
                    'status_code' => $statusCode,
                    'status_description' => $statusBeforeInfo['status_description'],
                    'bloked_statuses' => $blockedStatus,
                ]
            );
        }

        $basicDataPayload = $this->payloadBuilder->buildBasicDataPayload($analysis);

        $updateBasicDataResponse = $this->tooService->updateProposalBasicData(
            numeroFicha: $numeroFicha,
            payload: $basicDataPayload
        );

        if (!$this->responseWasSuccessful($updateBasicDataResponse)) {
            return $this->failResult(
                message: 'Erro ao atualizar dados básicos da ficha na Too.',
                step: 'too_reanalysis_update_basic_data',
                responses: [
                    'status_before' => $statusBeforeResponse,
                    'update_basic_data' => $updateBasicDataResponse,
                ],
                extra: [
                    'numeroProposta' => $numeroProposta,
                    'numeroFicha' => $numeroFicha,
                ]
            );
        }

        $motivos = $options['motivosReanalise'] ?? [10];

        $observacoes = $options['observacoes']
            ?? 'Reanálise solicitada pelo sistema após alteração dos dados do lead.';

        $reanalysisPayload = [
            'motivosReanalise' => array_values(array_map('intval', $motivos)),
            'observacoes' => $observacoes,
        ];

        $reanalysisResponse = $this->tooService->submitReanalysis(
            cpf: $cpf,
            numeroProposta: $numeroProposta,
            payload: $reanalysisPayload
        );

        if (!$this->responseWasSuccessful($reanalysisResponse)) {
            return $this->failResult(
                message: 'Erro ao solicitar reanálise de crédito na Too.',
                step: 'too_reanalysis_submit',
                responses: [
                    'status_before' => $statusBeforeResponse,
                    'update_basic_data' => $updateBasicDataResponse,
                    'reanalysis' => $reanalysisResponse,
                ],
                extra: [
                    'numeroProposta' => $numeroProposta,
                    'numeroFicha' => $numeroFicha,
                    'reanalysis_payload' => $reanalysisPayload,
                ]
            );
        }

        $currentPayload = $analysis->response_payload ?? [];

        if (is_string($currentPayload)) {
            $currentPayload = json_decode($currentPayload, true) ?: [];
        }

        $analysis->forceFill([
            'status' => 'processing',
            'result' => null,
            'provider_status' => 'Reanálise solicitada - aguardando processamento da Too',

            'response_payload' => array_merge($currentPayload, [
                'provider' => 'too',
                'numeroProposta' => (string) $numeroProposta,
                'numeroFicha' => (string) $numeroFicha,

                'too_reanalysis_attempt_id' => $attemptId,
                'too_is_reanalysis' => true,

                'too_reanalysis_status_before' => $statusBeforeResponse,
                'too_reanalysis_update_basic_data' => $updateBasicDataResponse,
                'too_reanalysis_request' => $reanalysisResponse,
                'too_reanalysis_payload' => $reanalysisPayload,

                'too_status_check_stopped' => false,
                'too_manual_sync_available' => false,
                'too_reanalysis_requested_at' => now()->toDateTimeString(),
            ]),

            'error_message' => null,
            'finished_at' => null,
        ])->save();

        SyncTooAnalysisStatusJob::dispatch(
            analysisId: $analysis->id,
            attemptId: $attemptId,
            isReanalysis: true,
            attemptNumber: 1
        )->delay(
            now()->addSeconds(
                (int) config(
                    'services.too.status_check_delay_seconds',
                    30
                )
            )
        );

        return $this->successResult(
            status: 'UnderAnalysis',
            quoteId: null,
            premiumAmount: null,
            responses: [
                'status_before' => $statusBeforeResponse,
                'update_basic_data' => $updateBasicDataResponse,
                'reanalysis' => $reanalysisResponse,
            ],
            extra: [
                'attempt_id' => $attemptId,
                'is_reanalysis' => true,
                'numeroProposta' => $numeroProposta,
                'numeroFicha' => $numeroFicha,
                'reanalysis_payload' => $reanalysisPayload,
                'provider_original_status' => 'ReanalysisRequested',
                'provider_original_description' => 'Reanálise solicitada e aguardando processamento.',
                'too_internal_decision' => 'UnderAnalysis',
                'can_quote' => false,
            ]
        );
    }

    /**
     * Centraliza a regra de decisão da Too.
     *
     * requestAnalysis() e getStatus() passam por aqui.
     */
    private function handleStatusAndMaybeQuote(
        InsuranceAnalysis $analysis,
        array $statusResponse,
        array $baseResponses,
        array $baseExtra = [],
        bool $scheduleNextCheck = false,
        ?string $attemptId = null,
        bool $isReanalysis = false,
    ): array {
        $statusData = $statusResponse['response'] ?? [];
        $statusInfo = $this->tooCreditDecision($statusData);

        $numeroFicha = $baseExtra['numeroFicha']
            ?? data_get($analysis->response_payload, 'numeroFicha')
            ?? data_get($analysis->response_payload, 'numeroProposta')
            ?? $analysis->proposal_id;

        $extra = array_merge($baseExtra, [
            'provider_original_status' => $statusInfo['status_code'],
            'provider_original_description' => $statusInfo['status_description'],
            'too_internal_decision' => $statusInfo['canonical'],
            'can_quote' => $statusInfo['can_quote'],
        ]);

        /*
         * Se ainda não pode cotar, apenas retorna o resultado da análise.
         *
         * Status 5  = Em análise de crédito
         * Status 16 = Pré-aprovado, mas sem cotação por enquanto
         * Status 6/11/12/14/15 = recusado/cancelado/expirado
         */
        if (!$statusInfo['can_quote']) {
            $analysis->update([
                'provider_status' => $statusInfo['status_description'] ?? $statusInfo['status_code'],
                'response_payload' => array_merge($analysis->response_payload ?? [], [
                    'status_latest' => $statusResponse,
                    'too_status_info' => $statusInfo,
                ]),
            ]);

            if($scheduleNextCheck && $statusInfo['canonical'] === 'UnderAnalysis'){
                if (blank($attemptId)) {
                    throw new \LogicException(
                        'attempt_id não informado para iniciar sincronização automática da Too.'
                    );
                }

                SyncTooAnalysisStatusJob::dispatch(
                    analysisId: $analysis->id,
                    attemptId: $attemptId,
                    isReanalysis: $isReanalysis,
                    attemptNumber: 1
                );
            }

            return $this->successResult(
                status: $this->statusForJob($statusInfo),
                quoteId: null,
                premiumAmount: null,
                responses: $baseResponses,
                extra: $extra
            );
        }

        /*
         * Status 8 = Análise aprovada.
         * Agora sim solicitamos cotação.
         */
        return $this->requestQuoteAfterApprovedStatus(
            analysis: $analysis,
            numeroFicha: $numeroFicha,
            statusResponse: $statusResponse,
            baseResponses: $baseResponses,
            statusInfo: $statusInfo,
            extra: $extra
        );
    }

    /**
     * Solicita cotação depois que a Too retornar status 8.
     */
    private function requestQuoteAfterApprovedStatus(
        InsuranceAnalysis $analysis,
        string|int|null $numeroFicha,
        array $statusResponse,
        array $baseResponses,
        array $statusInfo,
        array $extra = []
    ): array {
        if (!$numeroFicha) {
            return $this->failResult(
                message: 'Número da ficha ausente para solicitar cotação na Too.',
                step: 'request_quote_missing_numero_ficha',
                responses: $baseResponses,
                extra: $extra
            );
        }

        $quotePayload = $this->payloadBuilder->buildQuotePayload(
            analysis: $analysis,
            numeroFicha: $numeroFicha
        );

        $analysis->update([
            'request_payload' => array_merge($analysis->request_payload ?? [], [
                'quote_payload' => $quotePayload,
            ]),
        ]);

        $quoteResponse = $this->tooService->requestQuote($quotePayload);

        $responses = array_merge($baseResponses, [
            'quote' => $quoteResponse,
        ]);

        if (!$this->responseWasSuccessful($quoteResponse)) {
            return $this->failResult(
                message: 'Status aprovado, mas houve erro ao solicitar cotação na Too.',
                step: 'request_quote',
                responses: $responses,
                extra: $extra
            );
        }

        $quoteData = $quoteResponse['response'] ?? [];

        $numeroCotacao = $this->extractQuoteNumber($quoteData);
        $premiumAmount = $this->extractPremiumAmount($quoteData);
        $paymentConditions = $this->extractPaymentConditions($quoteData);
        $coverages = $this->extractQuoteCoverages($quoteData);

        $analysis->update([
            'quote_id' => $numeroCotacao ? (string) $numeroCotacao : $analysis->quote_id,
            'quote_number' => $numeroCotacao ? (string) $numeroCotacao : $analysis->quote_number,
            'premium_amount' => $premiumAmount ?? $analysis->premium_amount,
            'commercial_premium' => $premiumAmount ?? $analysis->commercial_premium,
            'available_plans' => $paymentConditions,
            'available_assistances' => $coverages,
            'provider_status' => $statusInfo['status_description'] ?? $statusInfo['status_code'],
            'response_payload' => array_merge($analysis->response_payload ?? [], [
                'status_latest' => $statusResponse,
                'quote_latest' => $quoteResponse,
                'too_status_info' => $statusInfo,
                'quote_summary' => [
                    'numeroCotacao' => $numeroCotacao,
                    'premiumAmount' => $premiumAmount,
                    'paymentConditions' => $paymentConditions,
                    'coverages' => $coverages,
                ],
            ]),
        ]);

        return $this->successResult(
            status: 'Approved',
            quoteId: $numeroCotacao,
            premiumAmount: $premiumAmount,
            responses: $responses,
            extra: array_merge($extra, [
                'numeroCotacao' => $numeroCotacao,
                'paymentConditions' => $paymentConditions,
                'coverages' => $coverages,
            ])
        );
    }

    /**
     * Decide o resultado da análise de crédito da Too.
     */
    private function tooCreditDecision(array $statusData): array
    {
        $proposta = data_get($statusData, 'proposta', []);

        $statusCode = data_get($proposta, 'status');
        $statusDescription = data_get($proposta, 'descricaoStatus');

        $statusCode = $statusCode !== null ? (int) $statusCode : null;

        /*
         * 8 = Análise Aprovada.
         * Este é o único status em que vamos solicitar cotação agora.
         */
        if ($statusCode === 8) {
            return [
                'canonical' => 'Approved',
                'can_quote' => true,
                'status_code' => $statusCode,
                'status_description' => $statusDescription,
            ];
        }

        /*
         * 16 = Análise pré-aprovada.
         * Como biometria não será implementada agora, não vamos cotar no 16.
         */
        if ($statusCode === 16) {
            return [
                'canonical' => 'PreApproved',
                'can_quote' => false,
                'status_code' => $statusCode,
                'status_description' => $statusDescription,
            ];
        }

        /*
         * 6  = Análise Reprovada
         * 11 = Proposta Expirada
         * 12 = Proposta Cancelada
         * 14 = Pagamento Não Autorizado
         * 15 = Crédito Reprovado I
         */
        if (in_array($statusCode, [6, 11, 12, 14, 15], true)) {
            return [
                'canonical' => 'Denied',
                'can_quote' => false,
                'status_code' => $statusCode,
                'status_description' => $statusDescription,
            ];
        }

        /*
         * 1 = Proposta Criada
         * 2 = Aguardando Análise Automática
         * 5 = Em Análise de Crédito
         * 7 = Análise Pendenciada
         */
        return [
            'canonical' => 'UnderAnalysis',
            'can_quote' => false,
            'status_code' => $statusCode,
            'status_description' => $statusDescription,
        ];
    }

    /**
     * Mantém compatibilidade com o RunProviderAnalysisJob.
     *
     * Se o Job ainda não entende "PreApproved", mandamos como UnderAnalysis,
     * mas preservamos o detalhe em too_internal_decision.
     */
    private function statusForJob(array $statusInfo): string
    {
        return match ($statusInfo['canonical']) {
            'Approved' => 'Approved',
            'Denied' => 'Denied',
            'PreApproved' => 'UnderAnalysis',
            default => 'UnderAnalysis',
        };
    }

    private function loadTooRelations(InsuranceAnalysis $analysis): void
    {
        $analysis->loadMissing([
            'lead.company',
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'lead.locador',
            'lead.imobiliariaInformada',
        ]);
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

   
    private function quoteRoot(array $quoteData): array
    {
        if (array_is_list($quoteData)) {
            return $quoteData[0] ?? [];
        }

        return $quoteData;
    }

    private function extractQuoteNumber(array $quoteData): ?string
    {
        $root = $this->quoteRoot($quoteData);

        $value = data_get($root, 'numeroCotacao')
            ?? data_get($root, 'NumeroCotacao')
            ?? data_get($root, 'numero_cotacao')
            ?? data_get($root, 'cotacao')
            ?? data_get($root, 'quoteId')
            ?? data_get($root, 'quote_id')
            ?? data_get($root, 'idCotacao');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    private function extractPremiumAmount(array $quoteData): ?float
    {
        $root = $this->quoteRoot($quoteData);

        $value = data_get($root, 'response.condicoesPagamento.premioBrutoTotal')
            ?? data_get($root, 'response.condicoesPagamento.premioLiquido')
            ?? data_get($root, 'condicoesPagamento.premioBrutoTotal')
            ?? data_get($root, 'condicoesPagamento.premioLiquido')
            ?? data_get($root, 'premiumAmount')
            ?? data_get($root, 'premioTotal')
            ?? data_get($root, 'valorPremioTotal')
            ?? data_get($root, 'valorPremio')
            ?? data_get($root, 'premio')
            ?? data_get($root, 'valorTotal')
            ?? data_get($root, 'total');

        /*
         * Fallback: se não vier total, soma os prêmios líquidos das coberturas.
         */
        if ($value === null) {
            $coverages = data_get($root, 'response.coberturas')
                ?? data_get($root, 'coberturas')
                ?? [];

            if (is_array($coverages) && count($coverages) > 0) {
                $value = collect($coverages)->sum(function ($coverage) {
                    return (float) ($coverage['premioLiquido'] ?? 0);
                });
            }
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(['R$', ' ', '.'], '', $value);
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function extractPaymentConditions(array $quoteData): ?array
    {
        $root = $this->quoteRoot($quoteData);

        $conditions = data_get($root, 'response.condicoesPagamento')
            ?? data_get($root, 'condicoesPagamento');

        return is_array($conditions) ? $conditions : null;
    }

    private function extractQuoteCoverages(array $quoteData): ?array
    {
        $root = $this->quoteRoot($quoteData);

        $coverages = data_get($root, 'response.coberturas')
            ?? data_get($root, 'coberturas');

        return is_array($coverages) ? $coverages : null;
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

    private function onlyNumbers(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }
}

