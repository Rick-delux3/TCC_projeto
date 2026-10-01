<?php

namespace App\Services\Insurance;

use App\Models\InsuranceAnalysisBatch;
use App\Models\Lead;
use App\Support\ManualLeadResultTags;
use Illuminate\Support\Str;

class InsuranceBatchResult
{
    public static function status(InsuranceAnalysisBatch $batch): ?string
    {
        $statuses = $batch->analyses->pluck('status')->map(fn ($status): string => mb_strtolower(trim((string) $status)));
        if ($statuses->isEmpty() || $statuses->contains(fn (string $status): bool => in_array($status, [
            'pending', 'processing', 'queued', 'running', 'manual_review', 'underanalysis', 'under_analysis', 'preapproved',
        ], true))) {
            return null;
        }
        if ($statuses->contains('approved')) {
            return 'approved';
        }

        return $statuses->every(fn (string $status): bool => in_array($status, ['rejected', 'denied', 'refused'], true))
            ? 'rejected' : 'failed';
    }

    public static function tagKey(InsuranceAnalysisBatch $batch): ?string
    {
        return ManualLeadResultTags::leadloversKey(self::status($batch) ?? '');
    }

    public static function readyForLeadLovers(Lead $lead, InsuranceAnalysisBatch $batch): bool
    {
        return in_array($batch->status, ['completed', 'completed_with_errors'], true)
            && $batch->analyses->count() >= $batch->total_providers
            && (int) $lead->last_analysis_batch_id === $batch->id
            && $lead->analysis_final_status === self::status($batch)
            && self::tagKey($batch) !== null;
    }

    public static function localTags(?string $tags, string $status): string
    {
        $values = collect(preg_split('/\s*,\s*/u', (string) $tags, -1, PREG_SPLIT_NO_EMPTY))
            ->reject(fn (string $tag): bool => in_array(Str::lower(Str::ascii(trim($tag))), [
                'aprovado', 'aprovados', 'recusado', 'recusados', 'reprovado', 'reprovados', 'ruim',
            ], true));
        if (in_array($status, ['approved', 'rejected'], true)) {
            $values->push(ManualLeadResultTags::label($status));
        }

        return $values->unique()->implode(', ');
    }
}
