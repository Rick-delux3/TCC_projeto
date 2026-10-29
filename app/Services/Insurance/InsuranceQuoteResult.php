<?php

namespace App\Services\Insurance;

use App\Models\InsuranceQuote;

final class InsuranceQuoteResult
{
    public function apply(InsuranceQuote $quote, string $attemptId, array $result): void
    {
        InsuranceAnalysisAttempt::run($quote, $attemptId, function () use ($quote, $attemptId, $result): void {
            (new InsuranceProviderResultWriter($attemptId))->apply($quote, $result);
        });
    }

    public function payment(InsuranceQuote $quote, string $attemptId, array $payment): void
    {
        InsuranceAnalysisAttempt::run($quote, $attemptId, function () use ($quote, $payment): void {
            $selection = $payment['selection'];
            $quote->forceFill([
                'payment_status' => $selection ? 'ready' : 'unavailable',
                'installment_amount' => $selection['amount'] ?? null,
                'installments' => $selection['count'] ?? $quote->installments,
                'response_payload' => array_merge($quote->providerResponsePayload(), ['payment' => $payment]),
            ])->save();
            $quote->events()->create([
                'event_type' => 'payment_consulted', 'status' => $quote->payment_status,
                'response' => $payment,
            ]);
        });
    }
}
