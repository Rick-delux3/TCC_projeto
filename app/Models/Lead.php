<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\InsuranceAnalysis;
use App\Models\InsuranceAnalysisBatch;



class Lead extends Model
{
    use HasFactory;

    protected $table = 'leads';

    // Permite salvar dados em massa via Webhook
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
    ];

    protected $casts = [
        'leadlovers_response' => 'array',
        'sent_to_leadlovers_at' => 'datetime',
        'aceite_termos' => 'boolean',
    ]; 

    /**
     * Um lead pode pertencer a uma imobiliária cadastrada.
     * Mas pode ser null para locatário, locador ou imobiliária não cadastrada.
     */
    public function company()
    {
        return $this->imobiliaria();
    }

    public function imobiliaria()
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
        return $this->hasOne(InsuranceAnalysis::class);
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
        $lastAnalysis = $this->insuranceAnalyses()
            ->latest('created_at')
            ->first();

        if (!$lastAnalysis) {
            return true;
        }

        $lastLeadUpdate = collect([
            $this->updated_at,
            optional($this->endereco)->updated_at,
        ])->filter()->max();

        return $lastLeadUpdate && $lastLeadUpdate->gt($lastAnalysis->created_at);
    }
}
