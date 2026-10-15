<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CatalogoAtividadeImportacao extends Model
{
    /** @use HasFactory<\Database\Factories\CatalogoAtividadeImportacaoFactory> */
    use HasFactory;

    protected $table = 'catalogo_atividade_importacoes';

    protected $fillable = [
        'seguradora', 'arquivo_origem', 'sha256_origem', 'arquivo_catalogo',
        'sha256_catalogo', 'quantidade_descricoes', 'quantidade_codigos',
        'codigos_ausentes', 'revisao_necessaria',
    ];

    protected $casts = [
        'quantidade_descricoes' => 'integer',
        'quantidade_codigos' => 'integer',
        'codigos_ausentes' => 'array',
        'revisao_necessaria' => 'boolean',
    ];
}
