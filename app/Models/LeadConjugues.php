<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadConjugues extends Model
{
    protected $table = 'lead_conjugues';

    protected $fillable = [
        'lead_id',
        'nome',
        'cpf'
    ];

    public function leads()
    {
        return $this->lead();
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
