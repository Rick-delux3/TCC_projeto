<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AtividadeEconomica extends Model
{
    /** @use HasFactory<\Database\Factories\AtividadeEconomicaFactory> */
    use HasFactory;

    protected $table = 'atividades_economicas';

    protected $fillable = ['descricao', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public static function normalizarDescricao(string $descricao): string
    {
        return mb_convert_case(trim($descricao), MB_CASE_FOLD, 'UTF-8');
    }

    public function setDescricaoAttribute(string $descricao): void
    {
        $this->attributes['descricao'] = trim($descricao);
        $this->attributes['descricao_normalizada'] = self::normalizarDescricao($descricao);
        $this->attributes['descricao_busca'] = self::normalizarBusca($descricao);
    }

    public static function normalizarBusca(string $texto): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($texto))));
    }

    public function scopeDisponiveis(Builder $query): Builder
    {
        return $query->where('ativo', true)->whereHas('codigos', function (Builder $codes): void {
            $codes->where('seguradora', 'tokio')->where('ativo', true);
        });
    }

    public function codigos(): HasMany
    {
        return $this->hasMany(AtividadeEconomicaCodigo::class);
    }
}
