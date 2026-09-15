<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DashboardActivityChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $occurredAt;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly string $resource,
        public readonly int $resourceId,
        public readonly ?int $companyId,
        public readonly string $change,
    )
    {
        $this->occurredAt = now()->toIso8601String();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('admins.dashboard'),
        ];

        if ($this->companyId !== null) {
            array_unshift(
                $channels,
                new PrivateChannel(
                    "companies.{$this->companyId}.dashboard"
                )
            );
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'dashboard.activity.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'resource' => $this->resource,
            'resource_id' => $this->resourceId,
            'company_id' => $this->companyId,
            'change' => $this->change,
            'occurred_at' => $this->occurredAt,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }


}
