<?php

namespace App\Models;

use App\Enums\TipoVinculoEmpresa;
use Illuminate\Database\Eloquent\Model;

class LeadConjugues extends Model
{
    protected $casts = ['tipo_vinculo' => TipoVinculoEmpresa::class, 'data_nascimento' => 'date'];

    protected $table = 'lead_conjugues';

    protected $fillable = [
        'lead_id',
        'nome',
        'cpf',
        'data_nascimento',
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
