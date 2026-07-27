<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    public const SYSTEM_ORIGINS = [
        'simulacao_publica',
        'imobiliaria_cadastrada',
        'imobiliaria_nao_cadastrada',
        'locatario',
        'locador',
    ];

    protected $table = 'leads';

    // Permite persistir os dados normalizados pelos formulários e serviços.
    protected $fillable = [
        'company_id',
        'tipo_solicitante',
        'cpf',
        'estado_civil',
        'imobiliaria',
        'nome',
        'email',
        'tel',
        'tags_originais',
        'status',
        'origem',
        'ip',
        'user_agent',
        'aceite_termos',
        'observacoes',
        'leadlovers_status',
        'leadlovers_response',
        'sent_to_leadlovers_at',
        'created_by_corretor_id',
        'updated_by_corretor_id',
        'reanalysis_unlocked_at',
    ];

    protected $casts = [
        'leadlovers_response' => 'array',
        'sent_to_leadlovers_at' => 'datetime',
        'aceite_termos' => 'boolean',
        'reanalysis_unlocked_at' => 'datetime',
    ];

    public function scopeCreatedThroughSystem(Builder $query): Builder
    {
        return $query->whereIn('origem', self::SYSTEM_ORIGINS);
    }

    /**
     * Um lead pode pertencer a uma imobiliária cadastrada.
     * Mas pode ser null para locatário, locador ou imobiliária não cadastrada.
     */
    public function company()
    {
        return $this->imobiliariaVinculada();
    }

    public function imobiliariaVinculada()
    {
        return $this->belongsTo(Imobiliaria::class, 'company_id');
    }

    public function endereco()
    {
        return $this->hasOne(LeadEnderecos::class);
    }

    public function despesas()
    {
        return $this->hasOne(LeadDespesas::class);
    }

    public function locadores()
    {
        return $this->locador();
    }

    public function locador()
    {
        return $this->hasOne(LeadLocadores::class);
    }

    public function conjugues()
    {
        return $this->conjuge();
    }

    public function conjuge()
    {
        return $this->hasOne(LeadConjugues::class);
    }

    public function leadImobInformada()
    {
        return $this->imobiliariaInformada();
    }

    public function imobiliariaInformada()
    {
        return $this->hasOne(LeadImobiliariaInformada::class);
    }

    public function insuranceAnalyses()
    {
        return $this->analisesSeguro();
    }

    public function analisesSeguro()
    {
        return $this->hasMany(InsuranceAnalysis::class);
    }

    public function latestInsuranceAnalysis()
    {
        return $this->ultimaAnaliseSeguro();
    }

    public function ultimaAnaliseSeguro()
    {
        return $this->hasOne(InsuranceAnalysis::class)->latestOfMany();
    }

    public function insuranceAnalysesBatches()
    {
        return $this->lotesAnalisesSeguro();
    }

    public function lotesAnalisesSeguro()
    {
        return $this->hasMany(InsuranceAnalysisBatch::class);
    }

    public function createdByAdmin()
    {
        return $this->createdByCorretor();
    }

    public function createdByCorretor()
    {
        return $this->belongsTo(Corretor::class, 'created_by_corretor_id');
    }

    public function updatedByAdmin()
    {
        return $this->updatedByCorretor();
    }

    public function updatedByCorretor()
    {
        return $this->belongsTo(Corretor::class, 'updated_by_corretor_id');
    }

    public function canRequestReanalysis(): bool
    {
        if (! $this->reanalysis_unlocked_at) {
            return false;
        }

        $lastAnalysis = $this->insuranceAnalyses()
            ->latest('created_at')
            ->first();

        if (! $lastAnalysis) {
            return true;
        }

        return $this->reanalysis_unlocked_at->gt($lastAnalysis->created_at);
    }

    public function canBeSentToToo()
    {
        if (! filled($this->cpf)) {
            return false;
        }

        if ($this->tipo_solicitante === 'locador') {
            return false;
        }

        return in_array($this->tipo_solicitante, [
            'imobiliaria_cadastrada',
            'imobiliaria_nao_cadastrada',
            'locatario',
        ], true);
    }

    public function hasFinalInsuranceResultForReanalysis(): bool
    {
        return in_array($this->analysis_final_status, [
            'approved',
            'rejected',
        ], true);
    }

    public function canRequestGeneralReanalysis(): bool
    {
        return $this->hasFinalInsuranceResultForReanalysis()
            && filled($this->reanalysis_unlocked_at);
    }
}
