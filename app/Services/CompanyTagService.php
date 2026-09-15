<?php

namespace App\Services;

use App\Models\LeadLoversTag;
use App\Support\ManualLeadResultTags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

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

    public function keyFromTitle(string $title): string
    {
        $normalizedTitle = $this->normalizeTagNameForComparison($title);

        foreach (ManualLeadResultTags::all() as $definition) {
            if (
                $this->normalizeTagNameForComparison($definition['label'])
                === $normalizedTitle
            ) {
                return $definition['leadlovers_key'];
            }
        }

        return Str::of($title)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }

    public function keyForRemoteTag(string $title, int $remoteTagId): ?string
    {
        $key = $this->keyFromTitle($title);

        if ($key === '') {
            return null;
        }

        $alreadyUsed = LeadLoversTag::query()
            ->where('key', $key)
            ->where('leadlovers_tag_id', '!=', $remoteTagId)
            ->exists();

        return $alreadyUsed ? null : $key;
    }

    public function normalizeTagNameForComparison(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    private function availableQuery(): Builder
    {
        $query = LeadLoversTag::query()
            ->where('active', true)
            ->where(function (Builder $query) {
                $query->whereNull('key')
                    ->orWhereNotIn(
                        'key',
                        ManualLeadResultTags::leadloversKeys()
                    );
            })
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
