<?php

namespace App\Services;

use App\Models\LeadLoversTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class CompanyTagService
{
    private const BLOCKED_TERMS = [
        'morna',
        'sem negocios',
        'sem negócios',
        'ativas',
        'ativa',
        'ativo',
        'status',
        'aprovado',
        'recusado',
        'reprovado',
        'ruim',
        'negociação',
        'negociacao',
        'carro',
        'atraso',
    ];

    public function availableTags(): Collection
    {
        return $this->availableQuery()
            ->orderBy('title')
            ->get([
                'leadlovers_tag_id',
                'title',
            ]);
    }

    public function hasAvailableTags(): bool
    {
        return $this->availableQuery()->exists();
    }

    public function isAvailable(int $tagId): bool
    {
        return $this->availableQuery()
            ->where('leadlovers_tag_id', $tagId)
            ->exists();
    }

    public function normalizeCompanyName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $name = trim(
            preg_replace('/\s+/u', ' ', $value) ?? $value
        );

        // Evita gerar "Imobiliária Imobiliária Nova Casa".
        $name = preg_replace(
            '/^imobili[aá]ria(?:\s*[-:]\s*|\s+|$)/iu',
            '',
            $name
        ) ?? $name;

        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return 'Imobiliária '.$name;
    }

    private function availableQuery(): Builder
    {
        $query = LeadLoversTag::query()
            ->where('active', true)
            ->where(function (Builder $query) {
                $query->where('title', 'like', 'Imobiliária %')
                    ->orWhere('title', 'like', 'Imobiliaria %');
            })
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('imobiliarias')
                    ->whereColumn(
                        'imobiliarias.leadlovers_tag_id',
                        'lead_lovers_tags.leadlovers_tag_id'
                    );
            });

        foreach (self::BLOCKED_TERMS as $term) {
            $query->where('title', 'not like', '%'.$term.'%');
        }

        return $query;
    }
}