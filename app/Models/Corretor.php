<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;

class Corretor extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'corretores';

    public const ROLE_CEO = 'CEO';
    public const ROLE_INTEGRANTE = 'integrante';

    protected $fillable = [
        'name',
        'cpf',
        'email',
        'password',
        'first_login_verified_at',
        'first_login_code_sent_at',
        'role',
        'permissions',
        'active',
        'invited_by_corretor_id',
        'invited_at',
        'invite_version',
        'invite_accepted_at',
        'invite_send_count',
        'invite_expires_at',
        'invite_last_sent_at',
        'password_set_at',
        'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'permissions' => 'array',
        'active' => 'boolean',

        'invited_at' => 'datetime',
        'invite_accepted_at' => 'datetime',
        'invite_expires_at' => 'datetime',
        'invite_last_sent_at' => 'datetime',
        'password_set_at' => 'datetime',

        'first_login_verified_at' => 'datetime',
        'first_login_code_sent_at' => 'datetime',
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
        return $this->role === self::ROLE_CEO;
    }

    public function isIntegrante(): bool
    {
        return $this->role === self::ROLE_INTEGRANTE;
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

    public function hasPermission(string $permission): bool
    {
        if($this->isCeo()){
          return true;  
        } 

        return in_array($permission, $this->permissions ?? [], true);
    }

    public function invitedBy()
    {
        return $this->belongsTo(Self::class, 'invited_by_corretor_id');
    }

    public function hasAcceptedInvitation(): bool
    {
        return filled($this->invite_accepted_at);
    }

    public function hasPendingInvitation(): bool
    {
        return $this->isIntegrante() && !$this->hasAcceptedInvitation();
    }

    public function invitationIsExpired(): bool
    {
        return $this->hasPendingInvitation() && filled($this->invite_expires_at) && $this->invite_expires_at->isPast();
    }

    public function hasValidPendingInvitation(): bool
    {
        return $this->hasPendingInvitation() && filled($this->invite_expires_at) && ! $this->invite_expires_at->isPast() && (int) $this->invite_version > 0;
    }


}
