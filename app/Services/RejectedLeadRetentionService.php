<?php

namespace App\Services;

use App\Exceptions\PermanentLeadTagException;
use App\Models\Lead;
use App\Models\LeadRetentionEvent;
use App\Support\ManualLeadResultTags;
use Carbon\CarbonImmutable;
use Throwable;

final class RejectedLeadRetentionService
{
    /**
     * Create a new class instance.
     */
    public const RETENTION_DAYS = 30;

    public function rejectedTagKey(): string
    {
        $tagKey = ManualLeadResultTags::leadloversKey(
            ManualLeadResultTags::REJECTED
        );

        if(! is_string($tagKey) || blank($tagKey)) {
            throw new PermanentLeadTagException(
                'A chave da tag recusada não está configurada.'
            );
        }

        return $tagKey;
    }

     /**
     * @param array<int, array{id: int, name: string, linkedAt: string}> $remoteTags
     */
    public function confirmedAtForRemoteTag(
        array $remoteTags,
        int $remoteTagId
    ): CarbonImmutable {
        $remoteTag = collect($remoteTags)->first(
            fn (mixed $tag): bool => is_array($tag)
                && (int) ($tag['id'] ?? 0) === $remoteTagId
        );

        if (
            ! is_array($remoteTag)
            || ! is_string($remoteTag['linkedAt'] ?? null)
        ) {
            throw new PermanentLeadTagException(
                'A data de vínculo da tag não foi encontrada na LeadLovers.'
            );
        }

        try {
            return CarbonImmutable::parse(
                $remoteTag['linkedAt']
            )->utc();
        } catch (Throwable $exception) {
            throw new PermanentLeadTagException(
                'A LeadLovers devolveu uma data de vínculo inválida.',
                previous: $exception
            );
        }
    }

    /**
     * O método deve ser chamado somente depois que a tag estiver confirmada
     * na resposta de listLeadTags().
     *
     * @param array<int, array{id: int, name: string, linkedAt: string}> $remoteTags
     */
    public function applyConfirmedFinalTag(
        Lead $lead,
        string $tagKey,
        int $remoteTagId,
        array $remoteTags,
        ?int $operationVersion
    ): void {
        $confirmedAt = $this->confirmedAtForRemoteTag(
            remoteTags: $remoteTags,
            remoteTagId: $remoteTagId,
        );

        $rejectedTagKey = $this->rejectedTagKey();

        $oldDeletionDueAt = $lead
            ->rejected_deletion_due_at
            ?->toImmutable();

        $newDeletionDueAt = $tagKey === $rejectedTagKey
            ? $confirmedAt->addDays(self::RETENTION_DAYS)
            : null;

        $event = null;

        if ($newDeletionDueAt !== null) {
            $scheduleChanged = $oldDeletionDueAt === null
                || ! $oldDeletionDueAt->equalTo($newDeletionDueAt);

            if ($scheduleChanged) {
                $event = LeadRetentionEvent::EVENT_SCHEDULED;
            }
        } elseif ($oldDeletionDueAt !== null) {
            $event = LeadRetentionEvent::EVENT_CANCELLED;
        }

        /*
         * O Job que chamou este serviço já pode ter preenchido
         * tags_originais e updated_by_corretor_id.
         * Este save persiste todas essas alterações em conjunto.
         */
        $lead->forceFill([
            'leadlovers_confirmed_final_tag_key' => $tagKey,
            'leadlovers_final_tag_confirmed_at' => $confirmedAt,
            'leadlovers_confirmed_tag_version' => $operationVersion,
            'rejected_deletion_due_at' => $newDeletionDueAt,
        ])->save();

        /*
         * Uma reconciliação repetida com exatamente o mesmo linkedAt
         * não cria outro evento nem reinicia os 30 dias.
         */
        if ($event === null) {
            return;
        }

        LeadRetentionEvent::query()->create([
            'lead_id' => $lead->id,
            'company_id' => $lead->company_id,
            'leadlovers_lead_id' => $lead->leadlovers_lead_id,
            'event' => $event,
            'confirmed_tag_key' => $tagKey,
            'operation_version' => $operationVersion,
            'confirmed_at' => $confirmedAt,
            'deletion_due_at' => $newDeletionDueAt
                ?? $oldDeletionDueAt,
            'context' => [
                'source' => 'remote_tag_confirmation',
                'remote_tag_id' => $remoteTagId,
            ],
        ]);
    }


}
