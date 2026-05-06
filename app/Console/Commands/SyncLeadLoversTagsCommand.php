<?php

namespace App\Console\Commands;

use App\Models\LeadLoversTag;
use App\Services\LeadLoversService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SyncLeadLoversTagsCommand extends Command
{
    protected $signature = 'leadlovers:sync-tags';

    protected $description = 'Sincroniza as tags cadastradas na LeadLovers com o banco local';

    public function handle(LeadLoversService $leadLovers): int
    {
        $this->info('Buscando tags na LeadLovers...');

        $response = $leadLovers->getAllTags();

        // Ajuste conforme a resposta real da API.
        $tags = $response['Tags'] ?? $response['Data'] ?? $response;

        if (!is_array($tags) || empty($tags)) {
            $this->error('Nenhuma tag encontrada ou resposta inesperada da API.');
            $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $count = 0;

        foreach ($tags as $tag) {
            $tagId = $tag['Id']
                ?? $tag['ID']
                ?? $tag['Code']
                ?? $tag['Tag']
                ?? null;

            $title = $tag['Title']
                ?? $tag['Name']
                ?? $tag['TagName']
                ?? null;

            if (!$tagId || !$title) {
                $this->warn('Tag ignorada: ' . json_encode($tag, JSON_UNESCAPED_UNICODE));
                continue;
            }

            LeadLoversTag::updateOrCreate(
                [
                    'leadlovers_tag_id' => $tagId,
                ],
                [
                    'title' => $title,
                    'key' => $this->generateKeyFromTitle($title),
                    'active' => true,
                    'raw_payload' => $tag,
                ]
            );

            $count++;
        }

        $this->info("Sincronização concluída. {$count} tags salvas/atualizadas.");

        return self::SUCCESS;
    }

    /**
     * Transforma o título da tag em uma chave interna.
     * Ex: "imobiliaria morna" vira "imobiliaria_morna".
     */
    private function generateKeyFromTitle(string $title): string
    {
        return Str::of($title)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }
}