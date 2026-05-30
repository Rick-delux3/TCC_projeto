<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CorretorActivityLog extends Model
{
    protected $table = 'logs_atividades_corretores';

     protected $fillable = [
        'corretor_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'description',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function corretor()
    {
        return $this->belongsTo(Corretor::class, 'corretor_id');
    }

    public function admin()
    {
        return $this->belongsTo(Corretor::class, 'corretor_id');
    }

    public function subject()
    {
        return $this->morphTo(
            name: 'subject',
            type: 'model_type',
            id: 'model_id'
        );
    }
}
