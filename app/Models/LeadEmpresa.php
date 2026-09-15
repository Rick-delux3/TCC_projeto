<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadEmpresa extends Model
{
    protected $table = 'lead_empresa';

    protected $fillable = [
        'lead_id',
        'cnpj',
        'cpf_responsavel',
        'nome_responsavel',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }
}
