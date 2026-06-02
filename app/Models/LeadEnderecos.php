<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadEnderecos extends Model
{
    protected $table = 'lead_enderecos';

    protected $fillable = [
        'lead_id',
        'cep',
        'logradouro',
        'numero',
        'complemento',
        'bairro',
        'cidade_imovel',
        'estado',
    ];


    public function lead(){
        return $this->belongsTo(Lead::class);
    }
}
