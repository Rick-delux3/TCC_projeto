<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadLocadores extends Model
{
    protected $table = 'lead_locadores';

    protected $fillable = [
        'lead_id',
        'nome',
        'telefone',
        'email'
    ];

    public function lead(){
        return $this->belongsTo(Lead::class);
    }
}
