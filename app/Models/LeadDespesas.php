<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadDespesas extends Model
{
    protected $table = 'lead_despesas';

    protected $fillable = [
        'lead_id',
        'valor_aluguel',
        'valor_agua',
        'valor_luz',
        'valor_gas',
        'valor_condominio',
        'valor_iptu',
        'outras_despesas',
        'valor_total_encargos',
    ];

    protected $casts = [
        'valor_aluguel' => 'decimal:2',
        'valor_agua' => 'decimal:2',
        'valor_luz' => 'decimal:2',
        'valor_gas' => 'decimal:2',
        'valor_condominio' => 'decimal:2',
        'valor_iptu' => 'decimal:2',
        'outras_despesas' => 'decimal:2',
        'valor_total_encargos' => 'decimal:2',
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
