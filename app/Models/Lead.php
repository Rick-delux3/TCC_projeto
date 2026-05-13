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
        'tipo_solicitante',
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
        'nome_imobiliaria_informada',
        'cnpj_imobiliaria_informada',
        'nome_locador',
        'telefone_locador',
        'email_locador',
        'responsavel_preenchimento',
        'telefone_responsavel',
        'tags_originais', 
        'status',
        'origem',
        'ip',
        'user_agent',
        'aceite_termos',
        'observacoes',
        'leadlovers_status',
        'leadlovers_response',
        'sent_to_leadlovers_at',

    ];

    protected $casts = [
        'valor_aluguel' => 'decimal:2',
        'outras_despesas' => 'decimal:2',
        'valor_total_encargos' => 'decimal:2',
        'leadlovers_response' => 'array',
        'sent_to_leadlovers_at' => 'datetime',
        'aceite_termos' => 'boolean',
    ]; 

    /**
     * Um lead pode pertencer a uma imobiliária cadastrada.
     * Mas pode ser null para locatário, locador ou imobiliária não cadastrada.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
