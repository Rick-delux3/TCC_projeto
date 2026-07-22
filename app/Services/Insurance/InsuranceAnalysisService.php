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
        $this->ensureEnabled();

        $lead->loadMissing('despesas');

        $leaseMonths = (int) config('services.pottencial.default_lease_months', 30);

        $start = $startDate
            ? Carbon::parse($startDate)
            : now();

        $end = $start->copy()->addMonthsNoOverflow($leaseMonths);

        $rent = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;

        $agua = $this->valorAgua($lead);
        $luz = $this->valorLuz($lead);

        $charges = ($this->expenseValue($lead, 'valor_condominio') ?? 0.0)
            + ($this->expenseValue($lead, 'valor_iptu') ?? 0.0)
            + ($this->expenseValue($lead, 'valor_gas') ?? 0.0)
            + $agua
            + $luz
            + ($this->expenseValue($lead, 'outras_despesas') ?? 0.0);

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
        $this->ensureEnabled();

        $analysis->loadMissing([
            'lead.company',
            'lead.endereco',
            'lead.despesas',
            'lead.conjuge',
            'lead.locador',
            'lead.imobiliariaInformada',
        ]);

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

        $pottencialStatus = $this->extractProviderStatus($response);

        $internalStatus = $this->mapInternalStatus($pottencialStatus);
        $resultStatus = $this->mapResultStatus($pottencialStatus);

        $analysis->update([
            'status' => $internalStatus,
            'result' => $resultStatus,

            'provider_status' => $pottencialStatus,

            'quote_id' => $this->extractQuoteIdFromResponse($response) ?? $analysis->quote_id,
            'quote_number' => $this->extractQuoteNumber($response) ?? $analysis->quote_number,
            'product_key' => $this->extractProductKey($response) ?? $analysis->product_key,

            'available_plans' => $response['availablePlans'] ?? $analysis->available_plans,
            'available_assistances' => $response['availableAssistances'] ?? $analysis->available_assistances,

            'premium_amount' => $this->extractPremiumAmount($response) ?? $analysis->premium_amount,
            'commercial_premium' => $this->extractCommercialPremium($response) ?? $analysis->commercial_premium,
            'gross_premium' => $this->extractGrossPremium($response) ?? $analysis->gross_premium,
            'iof' => $this->extractIof($response) ?? $analysis->iof,
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

    private function ensureEnabled(): void
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            throw new \LogicException('O sistema de análises está temporariamente desativado.');
        }
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

    private function valorAgua(Lead $lead): float
    {
        $rent = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;
        $valorAgua = $this->expenseValue($lead, 'valor_agua');

        if ($valorAgua !== null) {
            return $valorAgua;
        }

        return $rent * 0.10;
    }

    private function valorLuz(Lead $lead): float
    {
        $rent = $this->expenseValue($lead, 'valor_aluguel') ?? 0.0;
        $valorLuz = $this->expenseValue($lead, 'valor_luz');

        if ($valorLuz !== null) {
            return $valorLuz;
        }

        return $rent * 0.10;
    }

    private function expenseValue(Lead $lead, string $field): ?float
    {
        $despesas = $lead->despesas;
        $value = $despesas->{$field} ?? $lead->{$field} ?? null;

        return $value !== null && $value !== '' ? (float) $value : null;
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
}
