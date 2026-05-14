<?php

namespace App\Services\Insurance;

use App\Models\Lead;
use App\Models\InsuranceAnalysis;
use App\Services\PottencialService;
use App\Services\Insurance\Payloads\RentalGuaranteeQuotePayloadBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class InsuranceAnalysisService
{
    public function __construct(
        private RentalGuaranteeQuotePayloadBuilder $payloadBuilder,
        private PottencialService $pottencialService
    ) {}

    public function createPendingAnalysis(Lead $lead, ?string $startDate = null): InsuranceAnalysis
    {
        $leaseMonths = (int) config('services.pottencial.default_lease_months', 30);

        $start = $startDate
            ? Carbon::parse($startDate)
            : now();

        $end = $start->copy()->addMonthsNoOverflow($leaseMonths);

        $rent = (float) ($lead->valor_aluguel ?? 0);

        $agua = $this->valorAgua($lead);
        $luz = $this->valorLuz($lead);

        $charges = (float) ($lead->valor_condominio ?? 0)
            + (float) ($lead->valor_iptu ?? 0)
            + (float) ($lead->valor_gas ?? 0)
            + $agua
            + $luz
            + (float) ($lead->outras_despesas ?? 0);

        $analysis = InsuranceAnalysis::create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,

            'provider' => 'pottencial',
            'product' => 'fianca_locaticia_residencial',

            'status' => 'pending',
            'result' => null,

            'plan_key' => config('services.pottencial.default_plan_key', 'traditional'),
            'multiple' => $leaseMonths,

            'lease_start_date' => $start->toDateString(),
            'lease_end_date' => $end->toDateString(),

            'inhabited' => false,

            'rent_amount' => $rent,
            'charges_amount' => $charges,
            'total_monthly_amount' => $rent + $charges,

            'payment_type' => config('services.pottencial.default_payment_type', 'Boleto'),
            'installments' => (int) config('services.pottencial.default_installments', 12),
        ]);

        $analysis->events()->create([
            'event_type' => 'created',
            'status' => 'pending',
            'message' => 'Análise criada no sistema.',
        ]);

        return $analysis;
    }

    public function sendToPottencial(InsuranceAnalysis $analysis): InsuranceAnalysis
    {
        $analysis->loadMissing('lead');

        $payload = $this->payloadBuilder->build($analysis);

        $analysis->update([
            'status' => 'processing',
            'request_payload' => $payload,
            'requested_at' => now(),
            'error_message' => null,
        ]);

        $analysis->events()->create([
            'event_type' => 'sent_to_api',
            'status' => 'processing',
            'message' => 'Solicitação de análise enviada para a Pottencial.',
            'payload' => $payload,
        ]);

        $result = $this->pottencialService->createRentalGuaranteeQuote($payload);

        $this->applyPottencialResponse($analysis, $result);

        return $analysis->fresh();
    }

    public function syncStatus(InsuranceAnalysis $analysis): InsuranceAnalysis
    {
        if (!$analysis->quote_id) {
            $analysis->events()->create([
                'event_type' => 'failed',
                'status' => 'failed',
                'message' => 'Não foi possível consultar status: quote_id não encontrado.',
            ]);

            return $analysis;
        }

        $result = $this->pottencialService->getRentalGuaranteeQuote($analysis->quote_id);

        $this->applyPottencialResponse($analysis, $result, true);

        return $analysis->fresh();
    }

    private function applyPottencialResponse(
        InsuranceAnalysis $analysis,
        array $result,
        bool $isSync = false
    ): void {
        $response = $result['response'] ?? [];

        if (!($result['success'] ?? false)) {
            $analysis->update([
                'status' => 'failed',
                'result' => null,
                'response_payload' => $response,
                'error_message' => is_array($response)
                    ? json_encode($response, JSON_UNESCAPED_UNICODE)
                    : (string) $response,
                'finished_at' => now(),
            ]);

            $analysis->events()->create([
                'event_type' => 'failed',
                'status' => 'failed',
                'message' => 'Falha ao solicitar análise na Pottencial.',
                'response' => $result,
            ]);

            Log::warning('Falha na análise Pottencial', [
                'analysis_id' => $analysis->id,
                'result' => $result,
            ]);

            return;
        }

        $pottencialStatus = $response['status'] ?? null;

        $internalStatus = $this->mapInternalStatus($pottencialStatus);
        $resultStatus = $this->mapResultStatus($pottencialStatus);

        $analysis->update([
            'status' => $internalStatus,
            'result' => $resultStatus,

            'pottencial_status' => $pottencialStatus,

            'quote_id' => $response['quoteId'] ?? $analysis->quote_id,

            'available_plans' => $response['availablePlans'] ?? $analysis->available_plans,
            'available_assistances' => $response['availableAssistances'] ?? $analysis->available_assistances,

            'premium_amount' => $this->extractPremiumAmount($response) ?? $analysis->premium_amount,
            'insured_amount' => $this->extractInsuredAmount($response) ?? $analysis->insured_amount,

            'response_payload' => $response,

            'finished_at' => in_array($internalStatus, ['approved', 'rejected', 'failed'])
                ? now()
                : $analysis->finished_at,
        ]);

        $analysis->events()->create([
            'event_type' => $isSync ? 'status_synced' : $internalStatus,
            'status' => $internalStatus,
            'message' => $isSync
                ? 'Status da análise sincronizado com a Pottencial.'
                : 'Retorno da análise recebido da Pottencial.',
            'response' => $response,
        ]);
    }

    private function mapInternalStatus(?string $pottencialStatus): string
    {
        return match ($pottencialStatus) {
            'Approved' => 'approved',
            'Denied' => 'rejected',
            'UnderAnalysis', 'Pending' => 'manual_review',
            default => 'quoted',
        };
    }

    private function mapResultStatus(?string $pottencialStatus): ?string
    {
        return match ($pottencialStatus) {
            'Approved' => 'approved',
            'Denied' => 'rejected',
            'UnderAnalysis', 'Pending' => 'manual_review',
            default => null,
        };
    }

    private function valorAgua(Lead $lead): float
    {
        $rent = (float) ($lead->valor_aluguel ?? 0);

        if ($lead->valor_agua !== null && $lead->valor_agua !== '') {
            return (float) $lead->valor_agua;
        }

        return $rent * 0.10;
    }

    private function valorLuz(Lead $lead): float
    {
        $rent = (float) ($lead->valor_aluguel ?? 0);

        if ($lead->valor_luz !== null && $lead->valor_luz !== '') {
            return (float) $lead->valor_luz;
        }

        return $rent * 0.10;
    }

    private function extractPremiumAmount(array $response): ?float
    {
        if (isset($response['premiumAmount'])) {
            return (float) $response['premiumAmount'];
        }

        if (isset($response['premium']['total'])) {
            return (float) $response['premium']['total'];
        }

        if (isset($response['availablePlans'][0]['premiumAmount'])) {
            return (float) $response['availablePlans'][0]['premiumAmount'];
        }

        if (isset($response['availablePlans'][0]['premium']['total'])) {
            return (float) $response['availablePlans'][0]['premium']['total'];
        }

        return null;
    }

    private function extractInsuredAmount(array $response): ?float
    {
        if (isset($response['insuredAmount'])) {
            return (float) $response['insuredAmount'];
        }

        if (isset($response['availablePlans'][0]['insuredAmount'])) {
            return (float) $response['availablePlans'][0]['insuredAmount'];
        }

        return null;
    }
}