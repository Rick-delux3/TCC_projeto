<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceAnalysisRound extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['lead_snapshot' => 'encrypted:array', 'is_reanalysis' => 'boolean', 'lease_start_date' => 'immutable_date', 'lease_end_date' => 'immutable_date'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InsuranceAnalysisBatch::class, 'insurance_analysis_batch_id');
    }

    public function providerRuns(): HasMany
    {
        return $this->hasMany(InsuranceProviderRun::class);
    }
}
