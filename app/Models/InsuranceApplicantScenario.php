<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsuranceApplicantScenario extends Model
{
    protected $guarded = ['id'];

    public function providerRun(): BelongsTo
    {
        return $this->belongsTo(InsuranceProviderRun::class, 'insurance_provider_run_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(InsuranceQuote::class);
    }
}
