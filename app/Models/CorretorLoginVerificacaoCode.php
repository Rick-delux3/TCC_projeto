<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class CorretorLoginVerificacaoCode extends Model
{
    protected $table = 'corretor_login_verification_codes';

    protected $fillable = [
        'corretor_id',
        'code_hash',
        'expires_at',
        'attempts',
        'used_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function corretor(): BelongsTo
    {
        return $this->belongsTo(Corretor::class);
    }

    public function isExpired(): bool
    {
        return now()->greaterThan($this->expires_at);
    }

    public function isUsed(): bool
    {
        return !is_null($this->used_at);
    }

    public function reachedMaxAttempts(): bool
    {
        return $this->attempts >= 5; 
    }
}
