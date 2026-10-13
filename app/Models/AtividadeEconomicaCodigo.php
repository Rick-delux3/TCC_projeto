<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtividadeEconomicaCodigo extends Model
{
    /** @use HasFactory<\Database\Factories\AtividadeEconomicaCodigoFactory> */
    use HasFactory;

    protected $table = 'atividade_economica_codigos';

    protected $fillable = ['atividade_economica_id', 'seguradora', 'codigo', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function atividadeEconomica(): BelongsTo
    {
        return $this->belongsTo(AtividadeEconomica::class);
    }
}
