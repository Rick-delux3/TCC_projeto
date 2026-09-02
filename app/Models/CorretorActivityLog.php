<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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

    public function corretor(): BelongsTo
    {
        return $this->belongsTo(Corretor::class, 'corretor_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Corretor::class, 'corretor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(
            name: 'subject',
            type: 'model_type',
            id: 'model_id'
        );
    }
}
