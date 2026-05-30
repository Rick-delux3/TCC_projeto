<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadImobiliariaInformada extends Model
{
    protected $table = "lead_imobiliaria_informada";

    protected $fillable = [
        'lead_id',
        'nome_imobiliaria_informada',
        'cnpj_imobiliaria_informada',
        'responsavel_preenchimento',
        'telefone_responsavel',
    ];

    public function lead(){
        return $this->belongsTo(Lead::class);
    }
}
