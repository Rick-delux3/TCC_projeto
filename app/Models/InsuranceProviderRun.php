<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceProviderRun extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['finished_at' => 'datetime'];

    public function round(): BelongsTo
    {
        return $this->belongsTo(InsuranceAnalysisRound::class, 'insurance_analysis_round_id');
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(InsuranceAnalysis::class, 'insurance_analysis_id');
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(InsuranceApplicantScenario::class);
    }

    public function selectedQuote(): BelongsTo
    {
        return $this->belongsTo(InsuranceQuote::class, 'selected_quote_id');
    }
}
