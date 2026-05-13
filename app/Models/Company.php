<?php

namespace App\Models;

use App\Notifications\CompanyResetPasswordNotification;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use App\Models\Lead;
use Illuminate\Support\Str;
use Override;

class Company extends Model implements CanResetPasswordContract
{
    use HasFactory, Notifiable, CanResetPassword;


    protected $fillable = [
        'name', 'email', 'phone', 'cnpj',
        'password',
        'city', 'state', 'sincronizado_em',
        'sync_status', 'sync_started_at', 'sync_finished_at',
        'sync_error','lead_form_token', 'lead_form_active',
        'lead_access_code', 'leadlovers_tag_id', 'leadlovers_tag_name',
    ];

    protected $hidden = [
        'password',
        'lead_form_token',
    ];

    protected $casts = [
        'sincronizado_em' => 'datetime',
        'sync_started_at' => 'datetime',
        'sync_finished_at' => 'datetime',
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

    #[Override]
    protected static function booted(): void
    {
        static::creating(function (Company $company){
            // Token longo usado internamente ou para links técnicos.
            if (empty($company->lead_form_token)) {
                $company->lead_form_token = Str::random(64);
            }

            // Código curto que a imobiliária poderá digitar no formulário público.
            if(empty($company->lead_access_code)){
                $company->lead_access_code = self::generateLeadAccessCode();
            }
        });

    }

    public static function generateLeadAccessCode(): string
    {
        do{
            $code = self::randomAlphaNumeric(6);
        }
        while(self::where('lead_access_code', $code)->exists());

        return $code;
    }

    private static function randomAlphaNumeric(int $length = 6): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $code;
    }
}
