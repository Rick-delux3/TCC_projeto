<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Lead;
use App\Models\Company;
use App\Models\InsuranceAnalysisEvent;


class InsuranceAnalysis extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'company_id',
        'provider',
        'product',
        'status',
        'pottencial_status',
        'result',
        'quote_id',
        'proposal_id',
        'policy_id',
        'rent_amount',
        'charges_amount',
        'total_monthly_amount',
        'premium_amount',
        'insured_amount',
        'plan_key',
        'multiple',
        'lease_start_date',
        'lease_end_date',
        'inhabited',
        'available_plans',
        'available_assistances',
        'payment_type',
        'installments',
        'request_payload',
        'response_payload',
        'rejection_reason',
        'error_message',
        'quote_pdf_path',
        'requested_at',
        'finished_at',
        'pdf_generated_at',
        'email_sent_at',
    ];

    protected $casts = [
        'available_plans' => 'array',
        'available_assistances' => 'array',
        'request_payload' => 'array',
        'response_payload' => 'array',

        'lease_start_date' => 'date',
        'lease_end_date' => 'date',

        'inhabited' => 'boolean',

        'requested_at' => 'datetime',
        'finished_at' => 'datetime',
        'pdf_generated_at' => 'datetime',
        'email_sent_at' => 'datetime',

    ];



    public function Lead(){
        return $this->belongsTo(Lead::class);
    }

    public function Company(){
        return $this->belongsTo(Company::class);
    }

    public function events(){
        return $this->hasMany(InsuranceAnalysisEvent::class);
    }
}
