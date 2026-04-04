<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Lead extends Model
{
    use HasFactory;

    // Permite salvar dados em massa via Webhook
    protected $fillable = [
        'company_id',
        'cpf',
        'cpf_casado',
        'cidade',
        'estado',
        'imobiliaria',
        'nome', 
        'email', 
        'tel',
        'valor_aluguel',
        'tags_originais', 
        'status'
    ];

    /**
     * Relacionamento: Um Lead pertence a uma Imobiliária (Company)
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
