<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysis;
use App\Services\Insurance\Providers\TooInsuranceProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTooAnalysisStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(
        public int $analysisId,
        public string $attemptId,
        public bool $isReanalysis = false,
        public int $attemptNumber = 1
    ) {}

    public function handle(TooInsuranceProvider $provider): void
    {
        $analysis = InsuranceAnalysis::with('batch')
            ->find($this->analysisId);

        if (! $analysis) {
            return;
        }

        if (mb_strtolower((string) $analysis->provider) !== 'too') {
            return;
        }

        if (in_array(
            $analysis->status,
            ['approved', 'rejected', 'failed'],
            true
        )) {
            return;
        }

        $result = $provider->getStatus($analysis);

        $analysis->refresh();

        $response = $result['response'] ?? [];

        $providerStatus = data_get($response, 'status');
        $tooInternalDecision = data_get(
            $response,
            'too_internal_decision'
        );

        $providerOriginalStatus = data_get(
            $response,
            'provider_original_status'
        );

        $providerOriginalDescription = data_get(
            $response,
            'provider_original_description'
        );

        $delaySeconds = (int) config(
            'services.too.status_check_delay_seconds',
            20
        );

        $maxAttempts = (int) config(
            'services.too.status_check_max_attempts',
            15
        );

        $currentPayload = $this->payloadAsArray(
            $analysis->response_payload
        );

        $resultKey = $this->isReanalysis
            ? 'too_reanalysis_status_latest'
            : 'too_initial_status_latest';

        /*
        |--------------------------------------------------------------------------
        | Falha temporária na consulta
        |--------------------------------------------------------------------------
        */

        if (! ($result['success'] ?? false)) {
            $analysis->forceFill([
                'response_payload' => array_merge($currentPayload, [
                    $resultKey => $result,
                    'too_status_check_attempts' => $this->attemptNumber,
                    'too_last_auto_check_at' => now()->toDateTimeString(),
                ]),
                'error_message' => data_get($result, 'error')
                    ?? 'Falha ao consultar status automático da Too.',
            ])->save();

            $analysis->events()->create([
                'event_type' => 'too_auto_status_check_failed',
                'status' => 'processing',
                'message' => 'Falha temporária ao consultar o status automático da Too.',
                'payload' => $this->eventPayload(),
                'response' => $result,
            ]);

            if ($this->attemptNumber < $maxAttempts) {
                $this->dispatchNext($analysis, $delaySeconds);

                return;
            }

            $this->finishAsManualReview(
                analysis: $analysis,
                result: $result,
                message: 'As verificações automáticas da Too falharam. A consulta manual foi liberada.'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Pré-aprovado
        |--------------------------------------------------------------------------
        */

        if ($tooInternalDecision === 'PreApproved') {
            $this->finishAsManualReview(
                analysis: $analysis,
                result: $result,
                message: 'A Too retornou análise pré-aprovada. A análise ficou em revisão manual.'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Continua em análise
        |--------------------------------------------------------------------------
        */

        if (in_array(
            $providerStatus,
            ['UnderAnalysis', 'Pending'],
            true
        )) {
            $analysis->forceFill([
                'status' => 'processing',
                'result' => null,
                'provider_status' => $providerOriginalDescription
                    ?? $providerOriginalStatus
                    ?? 'Em Análise de Crédito',

                'response_payload' => array_merge($currentPayload, [
                    $resultKey => $result,
                    'too_status_check_stopped' => false,
                    'too_manual_sync_available' => false,
                    'too_status_check_attempts' => $this->attemptNumber,
                    'too_last_auto_check_at' => now()->toDateTimeString(),
                ]),

                'error_message' => null,
                'finished_at' => null,
            ])->save();

            $analysis->events()->create([
                'event_type' => 'too_waiting_credit_analysis',
                'status' => 'processing',
                'message' => 'A Too ainda está processando a análise de crédito.',
                'payload' => $this->eventPayload(),
                'response' => $result,
            ]);

            if ($this->attemptNumber < $maxAttempts) {
                $this->dispatchNext($analysis, $delaySeconds);

                return;
            }

            $this->finishAsManualReview(
                analysis: $analysis,
                result: $result,
                message: 'A Too continuou em análise após todas as verificações automáticas. A consulta manual foi liberada.'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Recusado
        |--------------------------------------------------------------------------
        */

        if ($providerStatus === 'Denied') {
            $analysis->forceFill([
                'status' => 'rejected',
                'result' => 'rejected',
                'provider_status' => $providerOriginalDescription
                    ?? $providerOriginalStatus
                    ?? 'Recusada',

                'response_payload' => array_merge($currentPayload, [
                    $resultKey => $result,
                    'too_status_check_stopped' => false,
                    'too_manual_sync_available' => false,
                    'too_status_check_attempts' => $this->attemptNumber,
                    'too_last_auto_check_at' => now()->toDateTimeString(),
                ]),

                'error_message' => null,
                'finished_at' => now(),
            ])->save();

            $this->createCompletedEvent(
                analysis: $analysis,
                result: $result,
                status: 'rejected',
                message: 'Análise da Too concluída como recusada.'
            );

            $this->dispatchCompletion($analysis);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Aprovado
        |--------------------------------------------------------------------------
        */

        if ($providerStatus === 'Approved') {
            $quoteId = data_get($response, 'quoteId');
            $premiumAmount = data_get($response, 'premiumAmount');

            $analysis->forceFill([
                'status' => 'approved',
                'result' => 'approved',
                'provider_status' => $providerOriginalDescription
                    ?? $providerOriginalStatus
                    ?? 'Aprovada',

                'quote_id' => filled($quoteId)
                    ? (string) $quoteId
                    : $analysis->quote_id,

                'quote_number' => filled($quoteId)
                    ? (string) $quoteId
                    : $analysis->quote_number,

                'premium_amount' => $premiumAmount
                    ?? $analysis->premium_amount,

                'commercial_premium' => $premiumAmount
                    ?? $analysis->commercial_premium,

                'available_plans' => data_get(
                    $response,
                    'paymentConditions'
                ) ?? $analysis->available_plans,

                'available_assistances' => data_get(
                    $response,
                    'coverages'
                ) ?? $analysis->available_assistances,

                'response_payload' => array_merge($currentPayload, [
                    $resultKey => $result,
                    'too_status_check_stopped' => false,
                    'too_manual_sync_available' => false,
                    'too_status_check_attempts' => $this->attemptNumber,
                    'too_last_auto_check_at' => now()->toDateTimeString(),
                ]),

                'error_message' => null,
                'finished_at' => now(),
            ])->save();

            $this->createCompletedEvent(
                analysis: $analysis,
                result: $result,
                status: 'approved',
                message: 'Análise da Too aprovada e cotação registrada.'
            );

            $this->dispatchCompletion($analysis);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Status inesperado
        |--------------------------------------------------------------------------
        */

        $this->finishAsManualReview(
            analysis: $analysis,
            result: $result,
            message: 'A Too retornou um status não reconhecido. A consulta manual foi liberada.'
        );
    }

    private function dispatchNext(
        InsuranceAnalysis $analysis,
        int $delaySeconds
    ): void {
        self::dispatch(
            analysisId: $analysis->id,
            attemptId: $this->attemptId,
            isReanalysis: $this->isReanalysis,
            attemptNumber: $this->attemptNumber + 1
        )->delay(now()->addSeconds($delaySeconds));
    }

    private function finishAsManualReview(
        InsuranceAnalysis $analysis,
        array $result,
        string $message
    ): void {
        $currentPayload = $this->payloadAsArray(
            $analysis->response_payload
        );

        $analysis->forceFill([
            'status' => 'manual_review',
            'result' => 'manual_review',
            'provider_status' => $message,

            'response_payload' => array_merge($currentPayload, [
                'too_status_check_stopped' => true,
                'too_manual_sync_available' => true,
                'too_status_check_attempts' => $this->attemptNumber,
                'too_last_auto_check_at' => now()->toDateTimeString(),
                'too_status_check_stopped_at' => now()->toDateTimeString(),
            ]),

            'finished_at' => now(),
        ])->save();

        $this->createCompletedEvent(
            analysis: $analysis,
            result: $result,
            status: 'manual_review',
            message: $message
        );

        $this->dispatchCompletion($analysis);
    }

    private function createCompletedEvent(
        InsuranceAnalysis $analysis,
        array $result,
        string $status,
        string $message
    ): void {
        $analysis->events()->create([
            'event_type' => $this->isReanalysis
                ? 'reanalysis_completed'
                : 'analysis_completed',

            'status' => $status,
            'message' => $message,
            'payload' => array_merge(
                $this->eventPayload(),
                [
                    'provider' => 'too',
                    'proposal_id' => $analysis->proposal_id,
                    'quote_id' => $analysis->quote_id,
                    'premium_amount' => $analysis->premium_amount,
                ]
            ),
            'response' => $result,
        ]);
    }

    private function dispatchCompletion(
        InsuranceAnalysis $analysis
    ): void {
        if (! $analysis->insurance_analysis_batch_id) {
            return;
        }

        CompleteInsuranceAnalysesBatchJob::dispatch(
            batchId: $analysis->insurance_analysis_batch_id,
            attemptId: $this->attemptId,
            isReanalysis: $this->isReanalysis
        );
    }

    private function eventPayload(): array
    {
        return [
            'attempt_id' => $this->attemptId,
            'is_reanalysis' => $this->isReanalysis,
            'attempt_number' => $this->attemptNumber,
            'checked_at' => now()->toDateTimeString(),
        ];
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