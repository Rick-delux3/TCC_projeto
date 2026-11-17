<?php

namespace App\Jobs;

use App\Exceptions\LeadLoversApiException;
use App\Models\Imobiliaria;
use App\Models\LeadLoversTag;
use App\Services\LeadLovers\CompanyTagService;
use App\Services\LeadLovers\LeadLoversApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;

class CreateCompanyLeadLoversTagJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $companyId)
    {
        $this->onQueue('leadlovers');
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('company-tag:'.$this->companyId))->releaseAfter(30)->expireAfter(180)];
    }

    public function handle(LeadLoversApiClient $api, CompanyTagService $tags): void
    {
        if (! config('services.leadlovers.enabled', false)) {
            return;
        }
        $company = Imobiliaria::query()->find($this->companyId);
        if (! $company || $company->leadlovers_tag_id !== null) {
            return;
        }
        $name = $company->name;
        try {
            $matches = collect($api->listTags())->filter(fn (array $tag): bool => $tags->normalizeTagNameForComparison($tag['name']) === $tags->normalizeTagNameForComparison($name));
            if ($matches->count() > 1) {
                throw new RuntimeException('Mais de uma tag encontrada para a empresa. Revise a associação.');
            }
            $remote = $matches->first() ?? $api->createTag($name);
        } catch (LeadLoversApiException $exception) {
            if (! $exception->isRetryable()) {
                $this->fail($exception);

                return;
            }
            throw $exception;
        }
        if ($tags->normalizeTagNameForComparison($remote['name']) !== $tags->normalizeTagNameForComparison($name)) {
            throw new RuntimeException('A tag retornada não corresponde ao nome da empresa.');
        }
        $company->getConnection()->transaction(function () use ($remote, $name, $tags): void {
            $company = Imobiliaria::query()->lockForUpdate()->find($this->companyId);
            if (! $company || $company->leadlovers_tag_id !== null || $company->name !== $name) {
                return;
            }
            if (Imobiliaria::query()->where('leadlovers_tag_id', $remote['id'])->whereKeyNot($company->id)->exists()) {
                throw new RuntimeException('A tag já está vinculada a outra empresa.');
            }
            $tag = LeadLoversTag::query()->firstOrNew(['leadlovers_tag_id' => $remote['id']]);
            if ($tag->exists && ! $tag->active) {
                throw new RuntimeException('A tag da empresa está desativada.');
            }
            $tag->fill(['title' => $remote['name'], 'raw_payload' => $remote]);
            if (! $tag->exists) {
                $tag->key = $tags->keyForRemoteTag($remote['name'], $remote['id']);
                $tag->active = true;
            }
            $tag->save();
            $company->update(['leadlovers_tag_id' => $remote['id'], 'leadlovers_tag_name' => $remote['name']]);
        });
    }
}
