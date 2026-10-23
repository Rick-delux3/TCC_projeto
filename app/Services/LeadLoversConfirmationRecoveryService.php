<?php

namespace App\Services;

use App\Jobs\ConfirmLeadLoversMachineJob;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class LeadLoversConfirmationRecoveryService
{
    public function eligible(Lead $lead): bool
    {
        $response = $lead->leadlovers_response ?? [];
        if (! is_array($response)) {
            return false;
        }

        $operation = $lead->leadlovers_initial_error_operation ?? ($response['operation'] ?? null);
        $status = $lead->leadlovers_initial_error_status ?? ($response['status_code'] ?? null);
        $code = $lead->leadlovers_initial_error_code ?? ($response['error_code'] ?? null);

        return $lead->leadlovers_status === 'failed'
            && (int) $lead->leadlovers_lead_id > 0
            && $lead->sent_to_leadlovers_at === null
            && $operation === 'machine_confirmation'
            && ($status === null || in_array((int) $status, [408, 425, 429, 500, 502, 503, 504], true))
            && in_array($code, [null, 'CONNECTION_FAILED', 'LOCAL_RATE_LIMIT', 'INVALID_RESPONSE', 'SERVICE_UNAVAILABLE', 'TOO_MANY_REQUESTS'], true)
            && filter_var(data_get($response, 'action.action_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
            && in_array(data_get($response, 'action.status'), ['pending', 'mapping', 'processing', 'done'], true);
    }

    public function canSchedule(): bool
    {
        return config('branding.active') === 'tcc'
            && config('services.leadlovers.enabled')
            && ! in_array(config('queue.connections.'.config('queue.default').'.driver'), [null, 'sync', 'null'], true);
    }

    public function schedule(int $leadId): bool
    {
        if (! $this->canSchedule()) {
            return false;
        }

        $requestId = (string) Str::uuid();
        $nextRetry = now()->addMinute();
        $previous = DB::transaction(function () use ($leadId, $requestId, $nextRetry): ?array {
            $lead = Lead::query()->whereKey($leadId)->lockForUpdate()->first();

            if ($lead === null || ! $this->eligible($lead)) {
                return null;
            }

            $previous = $lead->only(['leadlovers_status', 'leadlovers_response', 'leadlovers_recovery']);
            $lead->forceFill([
                'leadlovers_status' => 'processing',
                'leadlovers_response' => array_merge($lead->leadlovers_response, [
                    'phase' => 'machine_confirmation_pending',
                    'success' => false,
                ]),
                'leadlovers_recovery' => array_merge($lead->leadlovers_recovery ?? [], [
                    'request_id' => $requestId,
                    'source' => 'selective_recovery',
                    'mode' => 'machine_confirmation',
                    'status' => 'scheduled',
                    'operation' => 'machine_confirmation',
                    'reason' => 'confirmation_pending',
                    'retries_scheduled' => (int) data_get($lead->leadlovers_recovery, 'retries_scheduled', 0) + 1,
                    'confirmation_retries' => 0,
                    'started_at' => now()->toIso8601String(),
                    'next_retry_at' => $nextRetry->toIso8601String(),
                    'finished_at' => null,
                ]),
            ])->save();

            return $previous;
        });

        if ($previous === null) {
            return false;
        }

        try {
            ConfirmLeadLoversMachineJob::dispatch($leadId)
                ->onQueue('leadlovers')->delay($nextRetry)->afterCommit();
        } catch (Throwable $exception) {
            DB::transaction(function () use ($leadId, $requestId, $previous): void {
                $lead = Lead::query()->whereKey($leadId)->lockForUpdate()->first();

                if ($lead?->leadlovers_status === 'processing'
                    && data_get($lead->leadlovers_recovery, 'request_id') === $requestId
                    && data_get($lead->leadlovers_recovery, 'status') === 'scheduled') {
                    $lead->forceFill($previous)->save();
                }
            });

            throw $exception;
        }

        return true;
    }
}
