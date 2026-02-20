<?php

namespace App\Models;

use App\Notifications\CompanyResetPasswordNotification;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Company extends Model implements CanResetPasswordContract
{
    use HasFactory, Notifiable, CanResetPassword;


    protected $fillable = ['name', 'email', 'phone', 'password', 'city', 'state'];

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

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new CompanyResetPasswordNotification($token));
    }
}
