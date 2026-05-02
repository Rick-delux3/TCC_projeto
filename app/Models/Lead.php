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
        'conjuge_cpf',
        'conjuge_nome',
        'estado_civil',
        'cidade_imovel',
        'estado',
        'imobiliaria',
        'nome', 
        'email', 
        'tel',
        'valor_aluguel',
        'outras_despesas',
        'valor_total_encargos',
        'responsavel_preenchimento',
        'tags_originais', 
        'status',
        'origem',
        'ip',
        'user_agent',
        'leadlovers_status',
        'leadlovers_response',
        'sent_to_leadlovers_at',

    ];

    protected $casts = [
        'valor_aluguel' => 'decimal:2',
        'outras_despesas' => 'decimal:2',
        'valor_total_encargos' => 'decimal:2',
        'leadlovers_response' => 'array',
        'send_to_leadlovers_at' => 'datetime',
    ]; 

    /**
     * Relacionamento: Um Lead pertence a uma Imobiliária (Company)
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
