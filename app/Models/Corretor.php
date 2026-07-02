<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;

class Corretor extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'corretores';

    protected $fillable = [
        'name',
        'cpf',
        'email',
        'password',
        'first_login_verified_at',
        'first_login_code_sent_at',
        'role',
        'active',
        'last_login_at',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'password' => 'hashed',
        'first_login_verified_at' => 'datetime',
        'first_login_code_sent_at' => 'datetime',
        'active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function loginVerificationCodes()
    {
        return $this->hasMany(CorretorLoginVerificacaoCode::class, 'corretor_id');
    }

    public function hasVerifiedFirstLogin(): bool
    {
        return !is_null($this->first_login_verified_at);
    }

    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    public function isCeo(): bool
    {
        return $this->role === 'ceo';
    }

    public function setSenhaAttribute($value)
    {
        $this->attributes['password'] = bcrypt($value);
    }

    public function activityLogs()
    {
        return $this->logsAtividades();
    }

    public function logsAtividades()
    {
        return $this->hasMany(CorretorActivityLog::class);
    }
}
