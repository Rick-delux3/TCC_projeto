<?php

namespace App\Jobs;

use App\Models\InsuranceAnalysisBatch;
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
        public int $batchId
    ) {}

    public function handle(LeadLoversService $leadLoversService): void
    {
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

        if (!$lead || !$lead->email) {
            return;
        }

        /*
         * Evita aplicar a mesma tag final mais de uma vez.
         * Isso é importante porque jobs podem ser executados novamente.
         */
        if ($this->finalTagAlreadyApplied($batch)) {
            return;
        }

        /*
         * Descobre qual tag final deve ser aplicada:
         * - aprovados
         * - em_negociacao
         * - ruim
         */
        $tagKey = $this->resolveFinalTagKey($batch);

        if (!$tagKey) {
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

        if (!$tag) {
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
        } catch (\Throwable $e) {
            Log::warning('Erro ao aplicar tag final no LeadLovers', [
                'batch_id' => $batch->id,
                'lead_id' => $lead->id,
                'lead_email' => $lead->email,
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
            ->values();

        if ($statuses->isEmpty()) {
            return null;
        }

        /*
         * Se qualquer companhia aprovou, o resultado comercial é bom.
         */
        if ($statuses->contains('approved')) {
            return self::TAG_KEY_APPROVED;
        }

        /*
         * Se ainda existe algo cotado, pendente ou em análise manual,
         * não tratamos como ruim.
         */
        if (
            $statuses->contains('manual_review') ||
            $statuses->contains('quoted') ||
            $statuses->contains('processing') ||
            $statuses->contains('pending')
        ) {
            return self::TAG_KEY_NEGOTIATION;
        }

        /*
         * Se todas terminaram como rejected ou failed,
         * o lead entra como ruim.
         */
        $allBad = $statuses->every(function ($status) {
            return in_array($status, ['rejected', 'failed'], true);
        });

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
}