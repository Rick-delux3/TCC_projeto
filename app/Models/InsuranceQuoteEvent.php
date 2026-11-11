<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceQuoteEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['payload' => 'encrypted:array', 'response' => 'encrypted:array'];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(InsuranceQuote::class, 'insurance_quote_id');
    }
}
