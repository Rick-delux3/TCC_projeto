<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsuranceAnalysisBatch extends Model
{
    protected $fillable = [
        'lead_id',
        'company_id',
        'status',
        'total_providers',
        'completed_providers',
        'failed_providers',
        'started_at',
        'finished_at',
        'email_sent_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'email_sent_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function analyses()
    {
        return $this->hasMany(InsuranceAnalysis::class, 'insurance_analysis_batch_id');
    }
}
