<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class InsuranceAnalysisChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const NAME = 'insurance.analyses.changed';

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public function __construct(public readonly int $leadId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $lead = Lead::query()->select(['id', 'company_id'])->find($this->leadId);
        if (! $lead) {
            return [];
        }

        $channels = [new PrivateChannel(self::adminChannel($lead->id))];
        if ($lead->company_id !== null) {
            $channels[] = new PrivateChannel(self::companyChannel($lead->id, $lead->company_id));
        }

        return $channels;
    }

    public static function adminChannel(int $leadId): string
    {
        return "admins.leads.{$leadId}.analyses";
    }

    public static function companyChannel(int $leadId, int $companyId): string
    {
        return "companies.{$companyId}.leads.{$leadId}.analyses";
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /** @return array{lead_id: int} */
    public function broadcastWith(): array
    {
        return ['lead_id' => $this->leadId];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
