<?php

namespace App\Services;

use App\Exceptions\PermanentLeadTagException;
use App\Models\LeadLoversTag;
use App\Support\ManualLeadResultTags;
use Illuminate\Support\Collection;

final class LeadLoversResultTagService
{
    /**
     * @return Collection<string, LeadLoversTag>
     */
    public function catalog(): Collection
    {
        $expectedKeys = collect(ManualLeadResultTags::leadloversKeys());
        $catalog = LeadLoversTag::query()
            ->whereIn('key', $expectedKeys->all())
            ->get()
            ->keyBy('key');

        $missingKeys = $expectedKeys->diff($catalog->keys());

        if ($missingKeys->isNotEmpty()) {
            throw new PermanentLeadTagException(
                'Existem tags finais ausentes no catálogo local: '
                .$missingKeys->implode(', ')
            );
        }

        $invalidTag = $catalog->first(
            fn (LeadLoversTag $tag): bool => (int) $tag->leadlovers_tag_id <= 0
                || trim((string) $tag->title) === ''
        );

        if ($invalidTag instanceof LeadLoversTag) {
            throw new PermanentLeadTagException(
                'Uma tag final possui dados inválidos no catálogo local.'
            );
        }

        if ($catalog->pluck('leadlovers_tag_id')->map(
            fn (mixed $id): int => (int) $id
        )->duplicates()->isNotEmpty()) {
            throw new PermanentLeadTagException(
                'Existem tags finais diferentes utilizando o mesmo ID da LeadLovers.'
            );
        }

        if ($catalog->pluck('title')->map(
            fn (mixed $title): string => $this->normalizeTitle((string) $title)
        )->duplicates()->isNotEmpty()) {
            throw new PermanentLeadTagException(
                'Existem tags finais diferentes utilizando o mesmo título.'
            );
        }

        return $catalog;
    }

    /**
     * @param  Collection<string, LeadLoversTag>  $catalog
     */
    public function selectedTag(Collection $catalog, string $tagKey): LeadLoversTag
    {
        $selectedTag = $catalog->get($tagKey);

        if (! $selectedTag instanceof LeadLoversTag) {
            throw new PermanentLeadTagException(
                'A tag selecionada não foi encontrada no catálogo local.'
            );
        }

        if (! $selectedTag->active) {
            throw new PermanentLeadTagException(
                'A tag selecionada está desativada no catálogo local.'
            );
        }

        return $selectedTag;
    }

    /**
     * @param  array<int, array{id: int, name: string, linkedAt: string}>  $remoteTags
     * @param  Collection<string, LeadLoversTag>  $catalog
     * @return array{
     *     confirmed: bool,
     *     selectedPresent: bool,
     *     otherFinalTagIds: array<int, int>,
     *     remoteTagIds: array<int, int>,
     *     payload: array{applyTags: array<int, int>, removeTags: array<int, int>, leadsIds: array<int, int>}
     * }
     */
    public function plan(
        array $remoteTags,
        Collection $catalog,
        LeadLoversTag $selectedTag,
        int $remoteLeadId
    ): array {
        if ($remoteLeadId <= 0) {
            throw new PermanentLeadTagException(
                'O ID remoto do lead é inválido.'
            );
        }

        $remoteTagIds = collect($remoteTags)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->sort()
            ->values();
        $selectedTagId = (int) $selectedTag->leadlovers_tag_id;
        $otherFinalTagIds = $catalog
            ->reject(
                fn (LeadLoversTag $tag): bool => (int) $tag->leadlovers_tag_id === $selectedTagId
            )
            ->pluck('leadlovers_tag_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $remoteTagIds->containsStrict($id))
            ->unique()
            ->sort()
            ->values();
        $selectedPresent = $remoteTagIds->containsStrict($selectedTagId);

        return [
            'confirmed' => $selectedPresent && $otherFinalTagIds->isEmpty(),
            'selectedPresent' => $selectedPresent,
            'otherFinalTagIds' => $otherFinalTagIds->all(),
            'remoteTagIds' => $remoteTagIds->all(),
            'payload' => [
                'applyTags' => $selectedPresent ? [] : [$selectedTagId],
                'removeTags' => $otherFinalTagIds->all(),
                'leadsIds' => [$remoteLeadId],
            ],
        ];
    }

    /**
     * @param  Collection<string, LeadLoversTag>  $catalog
     */
    public function replaceLocalFinalTag(
        ?string $currentTagString,
        Collection $catalog,
        LeadLoversTag $selectedTag
    ): string {
        $finalTitles = $catalog
            ->pluck('title')
            ->filter(fn (mixed $title): bool => filled($title))
            ->map(fn (mixed $title): string => $this->normalizeTitle((string) $title))
            ->values();
        $currentTags = collect(
            preg_split('/\s*,\s*/', (string) $currentTagString)
        )
            ->filter(fn (mixed $tag): bool => filled($tag))
            ->map(fn (mixed $tag): string => trim((string) $tag))
            ->reject(
                fn (string $tag): bool => $finalTitles->containsStrict($this->normalizeTitle($tag))
            )
            ->values();

        $currentTags->push(trim((string) $selectedTag->title));

        return $currentTags
            ->unique(fn (string $tag): string => $this->normalizeTitle($tag))
            ->values()
            ->implode(', ');
    }

    private function normalizeTitle(string $title): string
    {
        return mb_strtolower(trim($title));
    }
}
