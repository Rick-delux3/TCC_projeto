<?php

namespace App\Services\Insurance;

use App\Models\InsuranceAnalysis;
use Illuminate\Support\Arr;

class AnalysisQuoteSummary
{
    /** @return array<string, mixed> */
    public function summarize(InsuranceAnalysis $analysis): array
    {
        $payload = $analysis->providerResponsePayload();
        $response = $payload['response'] ?? $payload;
        $response = is_array($response) ? $response : [];
        $too = $analysis->isTooProvider();

        if ($too) {
            $quote = data_get($payload, 'quote_latest.response', []);
            $quote = is_array($quote) ? ($quote['response'] ?? $quote) : [];
            $quote = is_array($quote) ? $quote : [];
            $conditions = $quote['condicoesPagamento']
                ?? data_get($payload, 'quote_summary.paymentConditions')
                ?? $analysis->available_plans ?? [];
            $gross = is_array($conditions) ? ($conditions['premioBrutoTotal'] ?? null) : null;
            $coverages = $quote['coberturas'] ?? data_get($payload, 'quote_summary.coverages')
                ?? $analysis->available_assistances ?? [];
            $start = $quote['inicioVigencia'] ?? $analysis->lease_start_date?->format('Y-m-d');
            $end = $quote['fimVigencia'] ?? $analysis->lease_end_date?->format('Y-m-d');
        } else {
            $quote = $response['quote'] ?? $response['data'] ?? $response;
            $quote = is_array($quote) ? $quote : [];
            $gross = $quote['grossPremium'] ?? $analysis->gross_premium;
            $coverages = $quote['coverages'] ?? collect($quote['riskObjects'] ?? [])->flatMap(
                fn ($risk): array => is_array($risk) && is_array($risk['coverages'] ?? null) ? $risk['coverages'] : []
            )->all();
            $start = $quote['policyPeriodStart'] ?? $analysis->lease_start_date?->format('Y-m-d');
            $end = $quote['policyPeriodEnd'] ?? $analysis->lease_end_date?->format('Y-m-d');
        }

        $total = $this->money($gross);

        return [
            'analysis_id' => $analysis->id,
            'provider' => $analysis->provider,
            'status' => $analysis->status,
            'quote_id' => $analysis->quote_id,
            'quote_number' => $analysis->quote_number,
            'proposal_id' => $analysis->proposal_id,
            'price' => [
                'total' => $analysis->isApprovedResult() ? ($total ?? $this->money($analysis->premium_amount)) : null,
                'basis' => $total !== null ? 'gross_total' : 'unconfirmed',
                'currency' => strtoupper(trim((string) ($quote['currency'] ?? 'BRL'))),
                'period_start' => $this->date($start),
                'period_end' => $this->date($end),
            ],
            'coverages' => $analysis->isApprovedResult() ? collect($coverages)->filter(fn ($coverage): bool => is_array($coverage))
                ->map(fn (array $coverage): array => [
                    'name' => $coverage['tipo'] ?? $coverage['name'] ?? $coverage['key'] ?? null,
                    'insured_amount' => $this->money($coverage['insuredAmount'] ?? null),
                    'declared_amount' => $this->money($coverage['verba'] ?? null),
                    'premium' => $this->money($coverage['premioLiquido'] ?? $coverage['premiumAmount'] ?? null),
                    'premium_basis' => $too ? 'net' : 'reported',
                    'indemnity_period' => $coverage['periodoIndenitario'] ?? null,
                    'contracted' => isset($coverage['contratada'])
                        ? filter_var($coverage['contratada'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
                    'details' => Arr::only($coverage, [
                        'key', 'name', 'tipo', 'insuredAmount', 'verba', 'premiumAmount', 'premioLiquido',
                        'grossPremium', 'commercialPremium', 'iof', 'deductible', 'periodoIndenitario', 'contratada',
                    ]),
                ])->values()->all() : [],
        ];
    }

    private function money(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim(str_replace(['R$', ' '], '', $value));
            if (str_contains($value, ',')) {
                $value = str_replace(',', '.', str_replace('.', '', $value));
            }
        }

        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0
            ? number_format((float) $value, 2, '.', '') : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:$|T| )/', $value, $parts)) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? substr($value, 0, 10) : null;
    }
}
