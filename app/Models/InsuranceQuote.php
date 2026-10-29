<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceQuote extends InsuranceExecution
{
    protected $table = 'insurance_quotes';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->mergeFillable(['insurance_applicant_scenario_id', 'installment_amount', 'payment_status', 'dispatched_at', 'poll_count']);
        $this->mergeCasts([
            'request_payload' => 'encrypted:array', 'response_payload' => 'encrypted:array',
            'available_plans' => 'encrypted:array', 'available_assistances' => 'encrypted:array',
            'installment_amount' => 'decimal:2', 'dispatched_at' => 'datetime',
        ]);
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(InsuranceApplicantScenario::class, 'insurance_applicant_scenario_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(InsuranceQuoteEvent::class);
    }

    public function currentAttemptContext(bool $lock = false): ?array
    {
        $run = $this->scenario->providerRun;
        $round = $run->round;
        $current = $run->analysis->currentAttemptContext($lock);

        return ($current['attempt_id'] ?? null) === $round->attempt_id
            ? ['attempt_id' => $round->attempt_id, 'is_reanalysis' => $round->is_reanalysis]
            : null;
    }
}
