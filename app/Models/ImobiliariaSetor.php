<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImobiliariaSetor extends Model
{
    /** @use HasFactory<\Database\Factories\ImobiliariaSetorFactory> */
    use HasFactory;

    protected $table = 'imobiliaria_setores';

    protected $fillable = ['key', 'name', 'email'];

    public function imobiliaria(): BelongsTo
    {
        return $this->belongsTo(Imobiliaria::class, 'company_id');
    }
}
