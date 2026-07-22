<?php

namespace App\Jobs;

use App\Exceptions\LeadLoversRateLimitedException;
use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Services\LeadLoversService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ApplyFinalAnalysisTagToLeadLoversJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /*
     * Essas keys precisam bater com as keys salvas na tabela lead_lovers_tags.
     *
     * Exemplo:
     * Tag na LeadLovers: "Aprovados"
     * Key gerada pelo seu comando: "aprovados"
     *
     * Tag na LeadLovers: "Em negociação"
     * Key gerada pelo seu comando: "em_negociacao"
     *
     * Tag na LeadLovers: "Ruim"
     * Key gerada pelo seu comando: "ruim"
     */
    private const TAG_KEY_APPROVED = 'aprovados';

    private const TAG_KEY_REJECTED = 'ruim';

    private const TAG_KEY_NEGOTIATION = 'em_negociacao';

    public function __construct(
        public int $batchId,
        public ?string $attemptId = null,
        public bool $isReanalysis = false,
    ) {}

    public function handle(LeadLoversService $leadLoversService): void
    {
        if (! config('features.insurance_analysis.enabled', false)
            || ! config('services.leadlovers.enabled', false)) {
            logger()->notice('Job de análise ignorado porque o módulo está desativado.', ['job' => static::class]);

            return;
        }

        /*
         * Carrega o lote com:
         * - lead: para pegar e-mail e dados do lead;
         * - analyses: para analisar os status das companhias;
         * - analyses.events: para verificar se a tag final já foi aplicada.
         */
        $batch = InsuranceAnalysisBatch::with([
            'lead',
            'analyses.events',
        ])->findOrFail($this->batchId);

        $lead = $batch->lead;

        if (! $lead || ! $lead->email) {
            return;
        }

        /*
         * Evita aplicar a mesma tag final mais de uma vez.
         * Isso é importante porque jobs podem ser executados novamente.

        /*
         * Descobre qual tag final deve ser aplicada:
         * - aprovados
         * - em_negociacao
         * - ruim
         */
        $tagKey = $this->resolveFinalTagKey($batch);

        $lead->forceFill([
            'analysis_final_status' => match ($tagKey) {
                self::TAG_KEY_APPROVED => 'approved',
                self::TAG_KEY_REJECTED => 'rejected',
                self::TAG_KEY_NEGOTIATION => 'negotiation',
                default => null,
            },
            'analysis_final_tag_key' => $tagKey,
            'last_analysis_batch_id' => $batch->id,
            'analysis_finalized_at' => now(),
        ])->save();

        if (! $tagKey) {
            $this->registerEventForAllAnalyses(
                batch: $batch,
                eventType: 'leadlovers_final_tag_not_resolved',
                status: null,
                message: 'Não foi possível resolver uma tag final para o lote.',
                payload: []
            );

            return;
        }

        /*
         * Busca a tag no banco local.
         * Essa tabela é preenchida pelo comando:
         *
         * php artisan leadlovers:sync-tags
         */
        $tag = LeadLoversTag::where('key', $tagKey)
            ->where('active', true)
            ->first();

        if (! $tag) {
            $this->registerEventForAllAnalyses(
                batch: $batch,
                eventType: 'leadlovers_final_tag_not_found',
                status: null,
                message: "Tag final não encontrada no banco local: {$tagKey}",
                payload: [
                    'expected_key' => $tagKey,
                ]
            );

            return;
        }

        if ($this->finalTagAlreadyApplied($batch)) {
            $this->appendLocalTag($lead, $tag->title);

            return;
        }

        try {
            /*
             * Aqui você aplica a tag no LeadLovers.
             *
             * Recomendo aplicar pelo ID da tag sincronizada:
             * $tag->leadlovers_tag_id
             *
             * Se o seu LeadLoversService aplicar por nome,
             * troque para:
             * $tag->title
             */
            $response = $leadLoversService->addTagToLeadById(
                $lead->email,
                $tag->leadlovers_tag_id
            );

            if (! $this->leadLoversResponseWasSuccessful($response)) {
                Log::warning('LeadLovers nao confirmou aplicacao da tag final', [
                    'batch_id' => $batch->id,
                    'lead_id' => $lead->id,
                    'lead_ref' => hash('sha256', mb_strtolower(trim($lead->email))),
                    'tag_key' => $tagKey,
                    'status' => $response['StatusCode']
                        ?? $response['statusCode']
                        ?? $response['status']
                        ?? null,
                ]);

                $this->registerEventForAllAnalyses(
                    batch: $batch,
                    eventType: 'leadlovers_final_tag_failed',
                    status: $tagKey,
                    message: "LeadLovers nao confirmou a aplicacao da tag final: {$tag->title}",
                    payload: [
                        'tag_id' => $tag->leadlovers_tag_id,
                        'tag_title' => $tag->title,
                        'tag_key' => $tag->key,
                    ],
                    response: $response
                );

                return;
            }

            /*
            * Correção principal:
            * A tag foi aplicada na LeadLovers, mas o dashboard lê as tags
            * do campo local leads.tags_originais.
            *
            * Portanto, precisamos salvar a tag final também no banco local.
            */
            $this->appendLocalTag($lead, $tag->title);

            $this->registerEventForAllAnalyses(
                batch: $batch,
                eventType: 'leadlovers_final_tag_applied',
                status: $tagKey,
                message: "Tag final aplicada no LeadLovers: {$tag->title}",
                payload: [
                    'tag_id' => $tag->leadlovers_tag_id,
                    'tag_title' => $tag->title,
                    'tag_key' => $tag->key,
                ],
                response: $response
            );
        } catch (LeadLoversRateLimitedException $e) {
            if ($this->attempts() >= $this->tries) {
                $this->registerEventForAllAnalyses(
                    batch: $batch,
                    eventType: 'leadlovers_final_tag_failed',
                    status: $tagKey,
                    message: 'Limite de requisições persistiu após várias tentativas.',
                    payload: [
                        'tag_key' => $tagKey,
                    ]
                );

                throw $e;
            }

            $retryAfter = max(
                1,
                $e->retryAfter
                    ?? (int) config('services.leadlovers.rate_limit_retry_seconds', 60)
            );

            Log::notice('Aplicação da tag devolvida à fila por rate limit.', [
                'batch_id' => $batch->id,
                'lead_id' => $lead->id,
                'tag_key' => $tagKey,
                'attempt' => $this->attempts(),
                'retry_after' => $retryAfter,
                'cloudflare_1015' => $e->cloudflareBlocked,
            ]);

            $this->release($retryAfter);
        } catch (\Throwable $e) {
            Log::warning('Erro ao aplicar tag final no LeadLovers', [
                'batch_id' => $batch->id,
                'lead_id' => $lead->id,
                'lead_ref' => hash('sha256', mb_strtolower(trim($lead->email))),
                'tag_key' => $tagKey,
                'message' => $e->getMessage(),
            ]);

            $this->registerEventForAllAnalyses(
                batch: $batch,
                eventType: 'leadlovers_final_tag_failed',
                status: $tagKey,
                message: $e->getMessage(),
                payload: [
                    'tag_key' => $tagKey,
                ]
            );
        }
    }

    private function leadLoversResponseWasSuccessful(array $response): bool
    {
        $statusCode = $response['StatusCode']
            ?? $response['statusCode']
            ?? $response['status']
            ?? null;

        if ($statusCode !== null) {
            return (int) $statusCode >= 200 && (int) $statusCode < 300;
        }

        $success = $response['Success'] ?? $response['success'] ?? null;

        if ($success === true) {
            return true;
        }

        $exception = $response['Exception'] ?? $response['exception'] ?? null;
        $error = $response['Error'] ?? $response['error'] ?? null;

        return blank($exception) && blank($error);

    }

    /**
     * Define a tag final do lote.
     *
     * Prioridade:
     * 1. Se alguma companhia aprovou, o lead é considerado aprovado.
     * 2. Se nenhuma aprovou, mas existe análise em negociação/manual, aplica em_negociacao.
     * 3. Se todas recusaram ou falharam, aplica ruim.
     */
    private function resolveFinalTagKey(InsuranceAnalysisBatch $batch): ?string
    {
        $statuses = $batch->analyses
            ->pluck('status')
            ->filter()
            ->map(fn ($status) => mb_strtolower((string) $status))
            ->values();

        if ($statuses->isEmpty()) {
            return null;
        }

        /*
         * Se qualquer companhia aprovou, o resultado comercial é bom.
         */
        if ($statuses->contains(fn ($status) => in_array($status, [
            'approved',
            'quoted',
        ], true))) {
            return self::TAG_KEY_APPROVED;
        }

        /*
         * Se ainda existe algo cotado, pendente ou em análise manual,
         * não tratamos como ruim.
         */
        if ($statuses->contains(fn ($status) => in_array($status, [
            'pending',
            'processing',
            'queued',
            'running',
            'manual_review',
            'underanalysis',
            'failed',
            'error',
        ], true))) {
            return self::TAG_KEY_NEGOTIATION;
        }

        /*
         * Se todas terminaram como rejected ou failed,
         * o lead entra como ruim.
         */
        $allBad = $statuses->every(fn ($status) => in_array($status, [
            'rejected',
            'denied',
            'refused',
        ], true));

        if ($allBad) {
            return self::TAG_KEY_REJECTED;
        }

        return null;
    }

    /**
     * Verifica se uma tag final já foi aplicada.
     *
     * Como você ainda não tem tabela de eventos do batch,
     * usamos os eventos das análises do lote.
     */
    private function finalTagAlreadyApplied(InsuranceAnalysisBatch $batch): bool
    {
        foreach ($batch->analyses as $analysis) {
            $alreadyApplied = $analysis->events
                ->where('event_type', 'leadlovers_final_tag_applied')
                ->isNotEmpty();

            if ($alreadyApplied) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registra o mesmo evento em todas as análises do lote.
     *
     * Isso ajuda você a ver no dashboard/histórico que a tag final foi aplicada
     * após o fechamento do lote.
     */
    private function registerEventForAllAnalyses(
        InsuranceAnalysisBatch $batch,
        string $eventType,
        ?string $status,
        string $message,
        array $payload = [],
        mixed $response = null
    ): void {
        foreach ($batch->analyses as $analysis) {
            $analysis->events()->create([
                'event_type' => $eventType,
                'status' => $status ?? $analysis->status,
                'message' => $message,
                'payload' => $payload,
                'response' => $response,
            ]);
        }
    }

    /**
     * Adiciona a tag final no campo local tags_originais do lead.
     *
     * O dashboard da imobiliária não consulta a LeadLovers em tempo real.
     * Ele exibe e filtra tags a partir de leads.tags_originais.
     *
     * Por isso, toda tag aplicada na LeadLovers também precisa ser
     * espelhada neste campo local.
     */
    private function appendLocalTag(Lead $lead, string $tagTitle): void
    {
        $tagTitle = trim($tagTitle);

        if ($tagTitle === '') {
            return;
        }

        $currentTags = collect(preg_split('/\s*,\s*/', (string) $lead->tags_originais))
            ->filter(fn ($tag) => filled($tag))
            ->map(fn ($tag) => trim($tag))
            ->values();

        $alreadyExists = $currentTags->contains(function ($tag) use ($tagTitle) {
            return mb_strtolower($tag) === mb_strtolower($tagTitle);
        });

        if (! $alreadyExists) {
            $currentTags->push($tagTitle);
        }

        $lead->forceFill([
            'tags_originais' => $currentTags
                ->unique(fn ($tag) => mb_strtolower($tag))
                ->values()
                ->implode(', '),
        ])->save();
    }

    private function replaceLocalFinalTag(Lead $lead, string $tagTitle): void
    {
        $finalTagTitles = LeadLoversTag::query()
            ->whereIn('key', [
                self::TAG_KEY_APPROVED,
                self::TAG_KEY_REJECTED,
                self::TAG_KEY_NEGOTIATION,
            ])
            ->pluck('title')
            ->filter()
            ->map(fn ($title) => mb_strtolower(trim((string) $title)))
            ->values();

        $currentTags = collect(preg_split('/\s*,\s*/', (string) $lead->tags_originais))
            ->filter(fn ($tag) => filled($tag))
            ->map(fn ($tag) => trim($tag))
            ->reject(fn ($tag) => $finalTagTitles->contains(mb_strtolower(trim($tag))))
            ->values();

        $currentTags->push($tagTitle);

        $lead->forceFill([
            'tags_originais' => $currentTags
                ->unique(fn ($tag) => mb_strtolower($tag))
                ->values()
                ->implode(', '),
        ])->save();
    }
}
