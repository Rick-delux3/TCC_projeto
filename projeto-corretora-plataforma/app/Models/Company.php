<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable; // Importante
use Illuminate\Notifications\Notifiable;

class Company extends Authenticatable
{
    use HasFactory, Notifiable;


    protected $fillable = ['name', 'email', 'phone', 'password'];

    protected $hidden = [
        'password',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function setSenhaAttribute($value)
    {
        $this->attributes['password'] = bcrypt($value);
    }
}
