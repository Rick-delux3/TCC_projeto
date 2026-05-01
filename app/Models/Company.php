<?php

namespace App\Models;

use App\Notifications\CompanyResetPasswordNotification;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Models\Lead;

class Company extends Model implements CanResetPasswordContract
{
    use HasFactory, Notifiable, CanResetPassword;


    protected $fillable = ['name', 'email', 'phone', 'password', 'city', 'state', 'sincronizado_em', 'sync_status', 'sync_started_at', 'sync_finished_at', 'sync_error','lead_form_token', 'lead_form_active'];

    protected $hidden = [
        'password',
        'lead_form_token',
    ];

    protected $casts = [
        'sincronizado_em' => 'datatime',
        'sync_started_at' => 'datatime',
        'sync_finished_at' => 'datatime',
        'lead_form_active' => 'boolean',

    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }


    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new CompanyResetPasswordNotification($token));
    }

    public function leads()
    {
        
        return $this->hasMany(Lead::class);
    }
}
