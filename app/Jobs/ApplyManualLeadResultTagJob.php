<?php

namespace App\Jobs;

use App\Exceptions\LeadLoversRateLimitedException;
use App\Models\Corretor;
use App\Models\CorretorActivityLog;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Services\LeadLoversService;
use App\Support\ManualLeadResultTags;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ApplyManualLeadResultTagJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    
    public int $tries = 3;
   
    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 900;

    public function __construct(
        public int $leadId,
        public string $result,
        public int $corretorId,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}

    /**
     * Impede dois Jobs de alteração de tag para o mesmo lead.
     */
    public function uniqueId(): string
    {
        return 'manual-lead-result-tag:'.$this->leadId;
    }

    /**
     * Proteção adicional contra execução simultânea.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->shared()
                ->releaseAfter(15)
                ->expireAfter(360),
        ];
    }

    /**
     * Intervalos para tentativas causadas por exceções comuns.
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(LeadLoversService $leadLovers): void
    {
        try {
            $this->process($leadLovers);
        } catch (LeadLoversRateLimitedException $exception) {
            /*
             * Se todas as tentativas foram utilizadas,
             * deixamos a exceção chegar ao worker.
             */
            if ($this->attempts() >= $this->tries) {
                throw $exception;
            }

            $retryAfter = max(
                1,
                $exception->retryAfter
                    ?? (int) config(
                        'services.leadlovers.rate_limit_retry_seconds',
                        60
                    )
            );

            Log::notice(
                'Alteração manual de tag devolvida à fila por rate limit.',
                [
                    'lead_id' => $this->leadId,
                    'corretor_id' => $this->corretorId,
                    'attempt' => $this->attempts(),
                    'retry_after' => $retryAfter,
                    'cloudflare_1015' => $exception->cloudflareBlocked,
                ]
            );

            $this->release($retryAfter);
        } catch (Throwable $exception) {
            Log::warning(
                'Tentativa de alteração manual da tag do lead falhou.',
                [
                    'lead_id' => $this->leadId,
                    'corretor_id' => $this->corretorId,
                    'result' => $this->result,
                    'attempt' => $this->attempts(),
                    'exception' => $exception::class,
                ]
            );

            /*
             * Relançar permite que o Laravel realize as tentativas
             * definidas em $tries e backoff().
             */
            throw $exception;
        }
    }

    /**
     * Executa o fluxo completo da alteração.
     */
    private function process(LeadLoversService $leadLovers): void
    {
        $lead = Lead::query()->findOrFail($this->leadId);

        $corretor = Corretor::query()->findOrFail(
            $this->corretorId
        );

        /*
         * A autorização é conferida novamente no processamento.
         *
         * Assim, se o integrante for desativado ou perder a
         * permissão enquanto o Job aguarda na fila, a operação
         * não será executada.
         */
        if (
            ! Gate::forUser($corretor)
                ->allows('manage-lead-tags')
        ) {
            throw new RuntimeException(
                'O corretor não possui permissão para gerenciar tags.'
            );
        }

        if (! config('services.leadlovers.enabled', false)) {
            throw new RuntimeException(
                'A integração com a LeadLovers está desativada.'
            );
        }

        /*
         * Não usamos INSURANCE_ANALYSIS_ENABLED.
         *
         * O gerenciamento manual de tags funciona de forma
         * independente do módulo de análises.
         */
        if (
            $lead->leadlovers_status !== 'sent'
            || $lead->sent_to_leadlovers_at === null
        ) {
            throw new RuntimeException(
                'O lead ainda não foi enviado para a LeadLovers.'
            );
        }

        if (
            blank($lead->email)
            || filter_var(
                $lead->email,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            throw new RuntimeException(
                'O lead não possui um e-mail válido.'
            );
        }

        /*
         * A validação será feita também pelo futuro Form Request,
         * mas o Job não deve confiar somente no controller.
         */
        if (
            ! in_array(
                $this->result,
                ManualLeadResultTags::keys(),
                true
            )
        ) {
            throw new RuntimeException(
                'O resultado solicitado não é permitido.'
            );
        }

        $selectedTagKey = ManualLeadResultTags::leadLoversKey(
            $this->result
        );

        if ($selectedTagKey === null) {
            throw new RuntimeException(
                'Não foi possível mapear o resultado para uma tag.'
            );
        }

        /*
         * Carrega as quatro tags finais pelo campo key.
         */
        $resultTagCatalog = $this->resultTagCatalog();

        $selectedTag = $resultTagCatalog->get(
            $selectedTagKey
        );

        if (! $selectedTag instanceof LeadLoversTag) {
            throw new RuntimeException(
                'A tag selecionada não foi encontrada no catálogo local.'
            );
        }

        if (! $selectedTag->active) {
            throw new RuntimeException(
                'A tag selecionada está desativada no catálogo local.'
            );
        }

        /*
         * Primeiro localizamos o lead pelo e-mail para obter
         * seu Code externo.
         */
        $leadResponse = $leadLovers->getLeadByEmail(
            $lead->email
        );

        $this->assertSuccessfulResponse(
            $leadResponse,
            'A consulta do lead falhou.'
        );

        $leadCode = $this->extractLeadCode(
            $leadResponse
        );

        if ($leadCode === null) {
            throw new RuntimeException(
                'O código externo do lead não foi localizado.'
            );
        }

        /*
         * Consulta o estado remoto antes de qualquer alteração.
         */
        $currentTagsResponse = $leadLovers->getLeadTagsByCode(
            $leadCode
        );

        $this->assertSuccessfulResponse(
            $currentTagsResponse,
            'A consulta das tags atuais falhou.'
        );

        $currentTags = $this->extractRemoteTags(
            $currentTagsResponse
        );

        $otherFinalTags = $resultTagCatalog
            ->filter(
                fn (LeadLoversTag $tag): bool =>
                    (string) $tag->key !== $selectedTagKey
            );

        $oldFinalTags = $otherFinalTags
        ->filter(
            fn (LeadLoversTag $tag): bool =>
                $this->remoteTagsContain(
                    $currentTags,
                    $tag
                )
        )
        ->values();

        /*
         * Aplica primeiro a nova tag.
         *
         * Se ela já estiver aplicada, não fazemos uma requisição
         * duplicada. Isso torna o Job seguro para reprocessamento.
         */
        if (
            ! $this->remoteTagsContain(
                $currentTags,
                $selectedTag
            )
        ) {
            $addResponse = $leadLovers->addTagToLeadById(
                $lead->email,
                $selectedTag->leadlovers_tag_id
            );

            $this->assertSuccessfulResponse(
                $addResponse,
                'A aplicação da nova tag falhou.'
            );
        }

        /*
         * Remove somente as outras tags finais.
         *
         * Tags da imobiliária, origem, campanha ou segmentação
         * não fazem parte do catálogo abaixo e são preservadas.
         */
        foreach ($oldFinalTags as $tag) {
            $removeResponse = $leadLovers->removeTagFromLead(
                $lead->email,
                $tag->leadlovers_tag_id
            );

            $this->assertSuccessfulResponse(
                $removeResponse,
                'A remoção de uma tag final anterior falhou.'
            );
        }

        /*
         * Consulta novamente para confirmar o resultado real.
         */
        $confirmationResponse = $leadLovers->getLeadTagsByCode(
            $leadCode
        );

        $this->assertSuccessfulResponse(
            $confirmationResponse,
            'A confirmação final das tags falhou.'
        );

        $confirmedTags = $this->extractRemoteTags(
            $confirmationResponse
        );

        /*
         * A tag escolhida precisa existir remotamente.
         */
        if (
            ! $this->remoteTagsContain(
                $confirmedTags,
                $selectedTag
            )
        ) {
            throw new RuntimeException(
                'A nova tag não foi confirmada na LeadLovers.'
            );
        }

        /*
         * Nenhuma das outras três tags finais pode permanecer.
         */
        $remainingFinalTags = $otherFinalTags
            ->filter(
                fn (LeadLoversTag $tag): bool =>
                    $this->remoteTagsContain(
                        $confirmedTags,
                        $tag
                    )
            );

        if ($remainingFinalTags->isNotEmpty()) {
            Log::warning(
                'Foram identificadas tags finais conflitantes após a alteração.',
                [
                    'lead_id' => $this->leadId,
                    'attempt' => $this->attempts(),

                    'selected_tag' => [
                        'key' => $selectedTagKey,
                        'id' => (int) $selectedTag->leadlovers_tag_id,
                    ],

                    'remaining_final_tags' => $remainingFinalTags
                        ->map(
                            fn (
                                LeadLoversTag $tag,
                                string $key
                            ): array => [
                                'key' => $key,
                                'id' => (int) $tag->leadlovers_tag_id,
                            ]
                        )
                        ->values()
                        ->all(),

                    'confirmed_remote_tag_ids' => $confirmedTags
                        ->pluck('id')
                        ->filter()
                        ->map(fn ($id): int => (int) $id)
                        ->values()
                        ->all(),
                ]
            );

            throw new RuntimeException(
                'A LeadLovers ainda possui outra tag final no lead.'
            );
        }

        /*
         * Somente depois da confirmação remota atualizamos o banco.
         */
        $this->persistConfirmedTags(
            confirmedTags: $confirmedTags,
            resultTagCatalog: $resultTagCatalog,
            selectedTag: $selectedTag,
        );
    }

    /**
     * Busca as quatro tags finais no catálogo local.
     */
    private function resultTagCatalog(): Collection
    {
        $expectedKeys = collect(
            ManualLeadResultTags::leadLoversKeys()
        );

        $catalog = LeadLoversTag::query()
            ->whereIn('key', $expectedKeys->all())
            ->get()
            ->keyBy('key');

        $missingKeys = $expectedKeys->diff(
            $catalog->keys()->all()
        );

        if ($missingKeys->isNotEmpty()) {
            throw new RuntimeException(
                'Existem tags finais ausentes no catálogo local: '
                .$missingKeys->implode(', ')
            );
        }

        $invalidTag = $catalog->first(
            fn (LeadLoversTag $tag): bool =>
                (int) $tag->leadlovers_tag_id <= 0
        );

        if ($invalidTag instanceof LeadLoversTag) {
            throw new RuntimeException(
                'Uma tag final possui ID LeadLovers inválido.'
            );
        }

        $duplicateIds = $catalog
            ->groupBy(
                fn (LeadLoversTag $tag): string =>
                    (string) ((int) $tag->leadlovers_tag_id)
            )
            ->filter(
                fn (Collection $tags): bool =>
                    $tags->count() > 1
            );

        if ($duplicateIds->isNotEmpty()) {
            throw new RuntimeException(
                'Existem tags finais diferentes utilizando o mesmo ID da LeadLovers.'
            );
        }

        return $catalog;
    }

    /**
     * Confirma se uma resposta representa sucesso HTTP.
     */
    private function assertSuccessfulResponse(
        array $response,
        string $message
    ): void {
        $statusCode = $response['StatusCode']
            ?? $response['statusCode']
            ?? $response['status']
            ?? null;

        if (
            ! is_numeric($statusCode)
            || (int) $statusCode < 200
            || (int) $statusCode >= 300
        ) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Extrai o Code externo dos formatos conhecidos da API.
     */
    private function extractLeadCode(
        array $response
    ): int|string|null {
        $candidates = [
            $response['Code'] ?? null,
            $response['code'] ?? null,
            data_get($response, 'Data.Code'),
            data_get($response, 'Data.code'),
            data_get($response, 'Data.0.Code'),
            data_get($response, 'Data.0.code'),
            data_get($response, 'Lead.Code'),
            data_get($response, 'Lead.code'),
            data_get($response, 'Result.Code'),
            data_get($response, 'Result.code'),
            data_get($response, '0.Code'),
            data_get($response, '0.code'),
        ];

        foreach ($candidates as $candidate) {
            if (
                is_int($candidate)
                && $candidate > 0
            ) {
                return $candidate;
            }

            if (
                is_string($candidate)
                && trim($candidate) !== ''
            ) {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Normaliza os possíveis formatos da lista de tags.
     *
     * Cada item retornado terá:
     * [
     *     'id' => int|null,
     *     'title' => string|null,
     * ]
     */
    private function extractRemoteTags(
        array $response
    ): Collection {
        $paths = [
            'Data.Tags',
            'Data.tags',
            'Data.Items',
            'Data.items',
            'Lead.Tags',
            'Lead.tags',
            'Tags',
            'tags',
            'Items',
            'items',
            'Data',
            'Result',
        ];

        $tagItems = null;

        foreach ($paths as $path) {
            $candidate = $this->tagListFromCandidate(
                data_get($response, $path)
            );

            if ($candidate !== null) {
                $tagItems = $candidate;

                break;
            }
        }

       
        if ($tagItems === null) {
            $tagItems = $this->tagListFromCandidate(
                $response
            ) ?? [];
        }

        return collect($tagItems)
            ->map(
                fn (mixed $tag): ?array =>
                    $this->normalizeRemoteTag($tag)
            )
            ->filter()
            ->values();
    }

    /**
     * Identifica se um valor contém uma lista ou uma única tag.
     */
    private function tagListFromCandidate(
        mixed $candidate
    ): ?array {
        if (! is_array($candidate)) {
            return null;
        }

        if ($candidate === []) {
            return [];
        }

        if (array_is_list($candidate)) {
            return $candidate;
        }

        if ($this->looksLikeTagPayload($candidate)) {
            return [$candidate];
        }

        $numericItems = collect($candidate)
            ->filter(
                fn (mixed $value, mixed $key): bool =>
                    is_int($key)
            )
            ->values()
            ->all();

        return $numericItems !== []
            ? $numericItems
            : null;
    }

    /**
     * Converte uma tag remota para um formato único.
     */
    private function normalizeRemoteTag(
        mixed $tag
    ): ?array {
        if (is_string($tag)) {
            $title = trim($tag);

            return $title === ''
                ? null
                : [
                    'id' => null,
                    'title' => $title,
                ];
        }

        if (! is_array($tag)) {
            return null;
        }

        if (
            isset($tag['Tag'])
            && is_array($tag['Tag'])
        ) {
            $tag = $tag['Tag'];
        }

        $idValue = $tag['Id']
            ?? $tag['id']
            ?? $tag['ID']
            ?? $tag['TagId']
            ?? $tag['tagId']
            ?? $tag['tag_id']
            ?? $tag['Code']
            ?? $tag['code']
            ?? $tag['Value']
            ?? null;

        $id = is_numeric($idValue)
            && (int) $idValue > 0
                ? (int) $idValue
                : null;

        $titleValue = $tag['Title']
            ?? $tag['title']
            ?? $tag['Name']
            ?? $tag['name']
            ?? $tag['TagName']
            ?? $tag['tagName']
            ?? $tag['Text']
            ?? null;

        $title = is_string($titleValue)
            && trim($titleValue) !== ''
                ? trim($titleValue)
                : null;

        if ($id === null && $title === null) {
            return null;
        }

        return [
            'id' => $id,
            'title' => $title,
        ];
    }

    /**
     * Verifica se o array se parece com uma tag da API.
     */
    private function looksLikeTagPayload(
        array $payload
    ): bool {
        return array_key_exists('Id', $payload)
            || array_key_exists('id', $payload)
            || array_key_exists('TagId', $payload)
            || array_key_exists('tagId', $payload)
            || array_key_exists('Title', $payload)
            || array_key_exists('title', $payload)
            || array_key_exists('TagName', $payload);
    }

    /**
     * Compara uma tag remota com uma tag do catálogo.
     *
     * Primeiro utiliza o ID. O título funciona como fallback.
     */
    private function remoteTagsContain(
        Collection $remoteTags,
        LeadLoversTag $expectedTag
    ): bool {
        $expectedId = (int) $expectedTag->leadlovers_tag_id;

        $expectedTitle = $this->normalizeTagTitle(
            (string) $expectedTag->title
        );

        return $remoteTags->contains(
            function (array $remoteTag) use (
                $expectedId,
                $expectedTitle
            ): bool {
                $remoteId = $remoteTag['id'] ?? $remoteTag['Id'] ?? null;


                if($remoteId !== null) {
                    return (int) $remoteId === $expectedId;
                }

                $remoteTitle = $remoteTag['title'] ?? $remoteTag['Title'] ?? null;

                if(
                    ! is_string($remoteTitle)
                    || trim($remoteTitle) === ''
                ) {
                    return false;
                }


                return $this->normalizeTagTitle(
                    $remoteTitle
                ) === $expectedTitle;
            }
        );
    }

    /**
     * Padroniza o título para comparação.
     */
    private function normalizeTagTitle(
        string $title
    ): string {
        return mb_strtolower(
            trim($title)
        );
    }

    /**
     * Atualiza o banco e registra auditoria em uma transação.
     */
    private function persistConfirmedTags(
        Collection $confirmedTags,
        Collection $resultTagCatalog,
        LeadLoversTag $selectedTag
    ): void {
        $titles = $this->resolveConfirmedTagTitles(
            $confirmedTags,
            $resultTagCatalog
        );

        $newTags = $titles->implode(', ');

        $resultLabel = ManualLeadResultTags::label(
            $this->result
        ) ?? $this->result;

        $selectedTagKey = ManualLeadResultTags::leadLoversKey(
            $this->result
        );

        DB::transaction(function () use (
            $newTags,
            $resultLabel,
            $selectedTagKey,
            $selectedTag
        ): void {
            /*
             * lockForUpdate protege a atualização local contra
             * outra transação simultânea.
             */
            $lead = Lead::query()
                ->lockForUpdate()
                ->findOrFail($this->leadId);

            $oldTags = $lead->tags_originais;
            $oldCorretorId = $lead->updated_by_corretor_id;

            $lead->forceFill([
                'tags_originais' => $newTags,
                'updated_by_corretor_id' => $this->corretorId,
            ])->save();

            CorretorActivityLog::create([
                'corretor_id' => $this->corretorId,
                'action' => 'lead_tag_update_completed',
                'model_type' => Lead::class,
                'model_id' => $lead->id,

                'old_values' => [
                    'tags_originais' => $oldTags,
                    'updated_by_corretor_id' => $oldCorretorId,
                ],

                'new_values' => [
                    'tags_originais' => $newTags,
                    'updated_by_corretor_id' => $this->corretorId,
                    'result' => $this->result,
                    'result_label' => $resultLabel,
                    'leadlovers_tag_key' => $selectedTagKey,
                    'leadlovers_tag_id' => (int) $selectedTag->leadlovers_tag_id,
                ],

                'description' => sprintf(
                    'Resultado comercial do lead alterado para "%s" após confirmação na LeadLovers.',
                    $resultLabel
                ),

                'ip' => $this->normalizedIp(),
                'user_agent' => $this->normalizedUserAgent(),
            ]);
        });
    }

    /**
     * Resolve os títulos que serão gravados em tags_originais.
     */
    private function resolveConfirmedTagTitles(
        Collection $confirmedTags,
        Collection $resultTagCatalog
    ): Collection {
        $titles = $confirmedTags->map(
            function (array $remoteTag) use (
                $resultTagCatalog
            ): ?string {
                if (
                    is_string($remoteTag['title'])
                    && trim($remoteTag['title']) !== ''
                ) {
                    return trim($remoteTag['title']);
                }

                if ($remoteTag['id'] === null) {
                    return null;
                }

                $catalogTag = $resultTagCatalog->first(
                    fn (LeadLoversTag $tag): bool =>
                        (int) $tag->leadlovers_tag_id
                            === (int) $remoteTag['id']
                );

                return $catalogTag instanceof LeadLoversTag
                    ? trim((string) $catalogTag->title)
                    : null;
            }
        );

        /*
         * Se a API retornar uma tag sem título e ela não existir
         * no catálogo, não podemos montar um espelho local seguro.
         */
        if (
            $titles->contains(
                fn (?string $title): bool =>
                    $title === null
            )
        ) {
            throw new RuntimeException(
                'A confirmação remota retornou uma tag sem título identificável.'
            );
        }

        return $titles
            ->filter(
                fn (?string $title): bool =>
                    filled($title)
            )
            ->unique(
                fn (string $title): string =>
                    $this->normalizeTagTitle($title)
            )
            ->values();
    }

    /**
     * Chamado quando o Job falhar definitivamente,
     * inclusive em caso de timeout.
     */
    public function failed(
        ?Throwable $exception
    ): void {
        try {
            $lead = Lead::query()->find($this->leadId);

            $corretorExists = Corretor::query()
                ->whereKey($this->corretorId)
                ->exists();

            if (! $lead || ! $corretorExists) {
                Log::error(
                    'Não foi possível registrar a falha da alteração de tag.',
                    [
                        'lead_id' => $this->leadId,
                        'corretor_id' => $this->corretorId,
                    ]
                );

                return;
            }

            CorretorActivityLog::create([
                'corretor_id' => $this->corretorId,
                'action' => 'lead_tag_update_failed',
                'model_type' => Lead::class,
                'model_id' => $lead->id,

                'old_values' => [
                    'tags_originais' => $lead->tags_originais,
                ],

                'new_values' => [
                    'requested_result' => $this->result,
                    'requested_label' =>
                        ManualLeadResultTags::label(
                            $this->result
                        ),
                    'leadlovers_tag_key' =>
                        ManualLeadResultTags::leadLoversKey(
                            $this->result
                        ),
                    'error' => mb_substr(
                        $exception?->getMessage()
                            ?? 'Falha desconhecida.',
                        0,
                        1000
                    ),
                ],

                'description' =>
                    'Não foi possível concluir a alteração manual da tag do lead.',

                'ip' => $this->normalizedIp(),
                'user_agent' => $this->normalizedUserAgent(),
            ]);

            Log::error(
                'Alteração manual da tag do lead falhou definitivamente.',
                [
                    'lead_id' => $this->leadId,
                    'corretor_id' => $this->corretorId,
                    'result' => $this->result,
                    'exception' => $exception
                        ? $exception::class
                        : null,
                ]
            );
        } catch (Throwable $logException) {
            /*
             * O método failed() não deve lançar uma segunda exceção
             * enquanto tenta registrar a primeira.
             */
            Log::critical(
                'Falha ao registrar auditoria do Job de tags.',
                [
                    'lead_id' => $this->leadId,
                    'corretor_id' => $this->corretorId,
                    'exception' => $logException::class,
                ]
            );
        }
    }

    private function normalizedIp(): ?string
    {
        if (blank($this->ip)) {
            return null;
        }

        return mb_substr(
            trim((string) $this->ip),
            0,
            45
        );
    }

    private function normalizedUserAgent(): ?string
    {
        if (blank($this->userAgent)) {
            return null;
        }

        return mb_substr(
            trim((string) $this->userAgent),
            0,
            2000
        );
    }
}