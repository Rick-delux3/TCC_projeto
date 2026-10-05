<?php

namespace App\Services\Insurance;

use Illuminate\Support\Collection;

class AnalysisQuoteComparison
{
    /** @return array{best_quote: array|null, comparison_issue: string|null} */
    public function compare(Collection $quotes): array
    {
        $approved = $quotes->where('status', 'approved');
        $issue = null;
        if ($approved->isEmpty()) {
            $issue = 'no_approved_quotes';
        } elseif ($approved->contains(fn (array $quote): bool => $quote['price']['basis'] !== 'gross_total'
            || (float) $quote['price']['total'] <= 0)) {
            $issue = 'missing_confirmed_total';
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
            'best_quote' => $issue === null ? $approved->sortBy(fn (array $quote): float => (float) $quote['price']['total'])->first() : null,
            'comparison_issue' => $issue,
        ];
    }
}
