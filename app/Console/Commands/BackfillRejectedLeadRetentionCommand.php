<?php

namespace App\Console\Commands;

use App\Exceptions\LeadLoversApiException;
use App\Exceptions\PermanentLeadTagException;
use App\Models\Lead;
use App\Models\LeadLoversTag;
use App\Models\LeadLoversTagOperation;
use App\Models\LeadRetentionEvent;
use App\Services\LeadLoversApiClient;
use App\Services\LeadLoversResultTagService;
use App\Services\LeadLoversTagOperationCoordinator;
use App\Services\RejectedLeadRetentionService;
use App\Support\ManualLeadResultTags;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillRejectedLeadRetentionCommand extends Command
{
    protected $signature = 'leads:backfill-rejected-retention
        {--limit=100 : Quantidade maxima de leads recusados a consultar}
        {--pretend : Consulta a API e informa o resultado sem escrever no banco}';

    protected $description = 'Reconcilia com seguranca o prazo de retencao de leads recusados antigos';

    public function handle(
        LeadLoversApiClient $leadLovers,
        LeadLoversResultTagService $resultTags,
        RejectedLeadRetentionService $retention,
    ): int {
        if (! config('services.leadlovers.enabled', false)) {
            $this->error('Integracao com a LeadLovers desativada. Nenhuma chamada foi realizada.');

            return self::FAILURE;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 1000,
            ],
        ]);

        if (! is_int($limit)) {
            $this->error('Informe --limit entre 1 e 1000.');

            return self::FAILURE;
        }

        try {
            $catalog = $resultTags->catalog();
            $rejectedTag = $resultTags->selectedTag(
                $catalog,
                $retention->rejectedTagKey()
            );
        } catch (PermanentLeadTagException $exception) {
            $this->error('O catalogo local de tags finais esta incompleto ou invalido.');

            return self::FAILURE;
        }

        $pretend = (bool) $this->option('pretend');
        $stats = [
            'scanned' => 0,
            'local_rejected' => 0,
            'scheduled' => 0,
            'would_schedule' => 0,
            'remote_not_rejected' => 0,
            'review_required' => 0,
            'concurrent' => 0,
            'errors' => 0,
        ];
        $stop = false;

        $this->info($pretend
            ? 'Backfill em modo pretend; nenhuma escrita sera realizada.'
            : 'Iniciando backfill seguro da retencao de leads recusados.');

        $query = Lead::query()
            ->whereNotNull('sent_to_leadlovers_at')
            ->where('leadlovers_status', 'sent')
            ->where('leadlovers_lead_id', '>', 0)
            ->whereNull('rejected_deletion_due_at')
            ->orderBy('id');

        foreach ($query->lazyById(min(100, $limit)) as $lead) {
            if ($stop || $stats['local_rejected'] >= $limit) {
                break;
            }

            $stats['scanned']++;

            if (! $this->hasExactRejectedTag($lead, $rejectedTag)) {
                continue;
            }

            $stats['local_rejected']++;

            if ($pretend) {
                $stop = ! $this->inspectLead(
                    lead: $lead,
                    leadLovers: $leadLovers,
                    resultTags: $resultTags,
                    retention: $retention,
                    rejectedTag: $rejectedTag,
                    catalog: $catalog,
                    pretend: true,
                    stats: $stats,
                );

                continue;
            }

            $lock = Cache::lock($this->overlapLockKey((int) $lead->id), 120);

            if (! $lock->get()) {
                $stats['concurrent']++;

                continue;
            }

            try {
                $stop = ! $this->inspectLead(
                    lead: $lead,
                    leadLovers: $leadLovers,
                    resultTags: $resultTags,
                    retention: $retention,
                    rejectedTag: $rejectedTag,
                    catalog: $catalog,
                    pretend: false,
                    stats: $stats,
                );
            } finally {
                $lock->release();
            }
        }

        $this->table(
            ['Resultado', 'Quantidade'],
            collect($stats)
                ->map(fn (int $value, string $key): array => [$key, $value])
                ->values()
                ->all()
        );

        return $stats['errors'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, LeadLoversTag>  $catalog
     * @param  array<string, int>  $stats
     */
    private function inspectLead(
        Lead $lead,
        LeadLoversApiClient $leadLovers,
        LeadLoversResultTagService $resultTags,
        RejectedLeadRetentionService $retention,
        LeadLoversTag $rejectedTag,
        Collection $catalog,
        bool $pretend,
        array &$stats,
    ): bool {
        $lead = Lead::query()->find($lead->id);

        if (
            ! $lead instanceof Lead
            || $lead->rejected_deletion_due_at !== null
            || ! $this->hasExactRejectedTag($lead, $rejectedTag)
        ) {
            return true;
        }

        $operation = $this->operation((int) $lead->id);

        if ($this->operationIsConcurrent($operation)) {
            $stats['concurrent']++;

            return true;
        }

        $expectedVersion = $operation?->version;

        try {
            $remoteTags = $leadLovers->listLeadTags(
                (int) $lead->leadlovers_lead_id
            );
        } catch (LeadLoversApiException $exception) {
            if ($exception->statusCode === 404) {
                $stats['review_required']++;
                $this->warn("Lead local {$lead->id}: ausente remotamente; revisao necessaria.");

                if (! $pretend) {
                    $this->recordReviewRequired($lead, $expectedVersion);
                }

                return true;
            }

            $stats['errors']++;
            $this->error(
                "Lead local {$lead->id}: consulta remota falhou"
                .($exception->statusCode !== null
                    ? " (HTTP {$exception->statusCode})"
                    : '')
                .($exception->errorCode !== null
                    ? " [{$exception->errorCode}]"
                    : '')
                .'. Nenhum prazo foi alterado.'
            );

            return ! (
                $exception->statusCode === 401
                || $exception->statusCode === 429
                || $exception->isTransient
            );
        }

        $plan = $resultTags->plan(
            remoteTags: $remoteTags,
            catalog: $catalog,
            selectedTag: $rejectedTag,
            remoteLeadId: (int) $lead->leadlovers_lead_id,
        );

        if (! $plan['confirmed']) {
            $stats['remote_not_rejected']++;
            $this->line("Lead local {$lead->id}: tag recusada exclusiva nao confirmada; ignorado.");

            return true;
        }

        if ($pretend) {
            $retention->confirmedAtForRemoteTag(
                remoteTags: $remoteTags,
                remoteTagId: (int) $rejectedTag->leadlovers_tag_id,
            );
            $stats['would_schedule']++;
            $this->line("Lead local {$lead->id}: prazo de retencao disponivel.");

            return true;
        }

        $scheduled = DB::transaction(function () use (
            $lead,
            $retention,
            $rejectedTag,
            $remoteTags,
            $expectedVersion,
        ): bool {
            $lockedLead = Lead::query()
                ->lockForUpdate()
                ->find($lead->id);

            if (
                ! $lockedLead instanceof Lead
                || $lockedLead->rejected_deletion_due_at !== null
                || ! $this->hasExactRejectedTag($lockedLead, $rejectedTag)
            ) {
                return false;
            }

            $lockedOperation = $this->operation(
                (int) $lockedLead->id,
                lockForUpdate: true,
            );

            if (
                $this->operationIsConcurrent($lockedOperation)
                || $lockedOperation?->version !== $expectedVersion
            ) {
                return false;
            }

            $retention->applyConfirmedFinalTag(
                lead: $lockedLead,
                tagKey: (string) $rejectedTag->key,
                remoteTagId: (int) $rejectedTag->leadlovers_tag_id,
                remoteTags: $remoteTags,
                operationVersion: $expectedVersion,
            );

            return true;
        });

        if ($scheduled) {
            $stats['scheduled']++;
            $this->line("Lead local {$lead->id}: prazo de retencao registrado.");
        } else {
            $stats['concurrent']++;
        }

        return true;
    }

    private function hasExactRejectedTag(
        Lead $lead,
        LeadLoversTag $rejectedTag,
    ): bool {
        $accepted = collect([
            $rejectedTag->title,
            $rejectedTag->key,
            ManualLeadResultTags::label(ManualLeadResultTags::REJECTED),
        ])->map(fn (mixed $tag): string => $this->normalizeTag((string) $tag));

        return collect(preg_split(
            '/\s*,\s*/u',
            (string) $lead->tags_originais,
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [])->contains(
            fn (mixed $tag): bool => $accepted->containsStrict(
                $this->normalizeTag((string) $tag)
            )
        );
    }

    private function normalizeTag(string $tag): string
    {
        return Str::of($tag)
            ->ascii()
            ->lower()
            ->replace(['_', '-'], ' ')
            ->squish()
            ->toString();
    }

    private function operation(
        int $leadId,
        bool $lockForUpdate = false,
    ): ?LeadLoversTagOperation {
        $query = LeadLoversTagOperation::query()
            ->where('lead_id', $leadId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function operationIsConcurrent(
        ?LeadLoversTagOperation $operation,
    ): bool {
        return $operation instanceof LeadLoversTagOperation
            && (
                $operation->inflight_version !== null
                || in_array($operation->phase, [
                    LeadLoversTagOperationCoordinator::PHASE_PENDING,
                    LeadLoversTagOperationCoordinator::PHASE_POSTING,
                    LeadLoversTagOperationCoordinator::PHASE_CONFIRMING,
                ], true)
            );
    }

    private function overlapLockKey(int $leadId): string
    {
        return 'laravel-queue-overlap:leadlovers-result-tag:lead:'.$leadId;
    }

    private function recordReviewRequired(
        Lead $lead,
        ?int $operationVersion,
    ): void {
        LeadRetentionEvent::query()->firstOrCreate([
            'lead_id' => $lead->id,
            'event' => LeadRetentionEvent::EVENT_REVIEW_REQUIRED,
            'operation_version' => $operationVersion,
        ], [
            'company_id' => $lead->company_id,
            'leadlovers_lead_id' => $lead->leadlovers_lead_id,
            'confirmed_tag_key' => null,
            'confirmed_at' => null,
            'deletion_due_at' => null,
            'context' => [
                'source' => 'rejected_retention_backfill',
                'verification' => 'remote_lead_not_found',
                'remote_status' => 404,
            ],
        ]);
    }
}
