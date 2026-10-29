<?php

namespace App\Services\Insurance;

use Illuminate\Support\Collection;

class AnalysisQuoteComparison
{
    /** @return array{best_quote: array|null, comparison_issue: string|null} */
    public function compare(Collection $quotes): array
    {
        $approved = $quotes->where('status', 'approved');
        $monthly = $approved->contains(fn (array $quote): bool => ($quote['price']['comparison_basis'] ?? null) === 'installment');
        $priceKey = $monthly ? 'installment_amount' : 'total';
        $issue = null;
        if ($approved->isEmpty()) {
            $issue = 'no_approved_quotes';
        } elseif ($approved->contains(fn (array $quote): bool => ($monthly
            ? ($quote['price']['comparison_basis'] ?? null) !== 'installment'
            : $quote['price']['basis'] !== 'gross_total')
            || (float) ($quote['price'][$priceKey] ?? 0) <= 0)) {
            $issue = $monthly ? 'missing_confirmed_installment' : 'missing_confirmed_total';
        } elseif ($approved->contains(fn (array $quote): bool => preg_match('/^[A-Z]{3}$/D', $quote['price']['currency']) !== 1)
            || $approved->pluck('price.currency')->unique()->count() > 1) {
            $issue = 'incomparable_currencies';
        } elseif ($approved->count() > 1 && (
            $approved->contains(fn (array $quote): bool => ! $quote['price']['period_start'] || ! $quote['price']['period_end']
                || $quote['price']['period_end'] <= $quote['price']['period_start'])
            || $approved->map(fn (array $quote): array => [$quote['price']['period_start'], $quote['price']['period_end']])->unique()->count() > 1
        )) {
            $issue = 'incomparable_periods';
        }

        return [
            'best_quote' => $issue === null ? $approved->sortBy(fn (array $quote): float => (float) $quote['price'][$priceKey])->first() : null,
            'comparison_issue' => $issue,
        ];
    }
}
