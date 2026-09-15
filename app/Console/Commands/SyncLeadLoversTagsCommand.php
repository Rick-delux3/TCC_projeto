<?php

namespace App\Console\Commands;

use App\Exceptions\LeadLoversApiException;
use App\Models\LeadLoversTag;
use App\Services\CompanyTagService;
use App\Services\LeadLoversApiClient;
use Illuminate\Console\Command;

class SyncLeadLoversTagsCommand extends Command
{
    protected $signature = 'leadlovers:sync-tags';

    protected $description = 'Sincroniza as tags cadastradas na LeadLovers com o banco local';

    public function handle(
        LeadLoversApiClient $leadLovers,
        CompanyTagService $companyTags,
    ): int {
        if (! config('services.leadlovers.enabled', false)) {
            $this->warn('Integração com a LeadLovers desativada. Nenhuma chamada foi realizada.');

            return self::FAILURE;
        }

        $this->info('Buscando tags na LeadLovers...');

        try {
            $tags = $leadLovers->listTags();
        } catch (LeadLoversApiException $exception) {
            $this->error(
                'Não foi possível sincronizar as tags. '
                .$exception->safeReason
            );

            return self::FAILURE;
        }

        $count = 0;

        foreach ($tags as $tag) {
            $tagId = $tag['id'];
            $title = $tag['name'];
            $localTag = LeadLoversTag::query()->firstOrNew([
                'leadlovers_tag_id' => $tagId,
            ]);

            if (! $localTag->exists) {
                $localTag->key = $companyTags->keyForRemoteTag(
                    $title,
                    $tagId
                );
                $localTag->active = true;
            }

            $localTag->title = $title;
            $localTag->raw_payload = $tag;
            $localTag->save();

            $count++;
        }

        $this->info("Sincronização concluída. {$count} tags salvas/atualizadas.");

        return self::SUCCESS;
    }
}
