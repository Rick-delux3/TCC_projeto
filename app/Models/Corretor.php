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
        'role',
        'active',
        'last_login_at',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

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
