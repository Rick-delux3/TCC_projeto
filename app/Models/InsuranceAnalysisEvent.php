<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\InsuranceAnalysis;

class InsuranceAnalysisEvent extends Model
{
    use HasFactory;

    protected $table = 'eventos_analises_seguro';

    protected $fillable = [
        'insurance_analysis_id',
        'event_type',
        'status',
        'message',
        'payload',
        'response',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
    ];

    public function analysis(){
        return $this->analise();
    }

    public function analise()
    {
        return $this->belongsTo(InsuranceAnalysis::class, 'insurance_analysis_id');
    }
}
