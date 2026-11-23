<?php

namespace App\Services\Dashboard;

use App\Models\Corretor;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class LeadNotifications
{
    private const TABLE = 'lead_notification_reads';

    public function count(User|Corretor $viewer): int
    {
        return $this->unread($viewer)->count();
    }

    public function markAsRead(User|Corretor $viewer): void
    {
        $column = $this->viewerColumn($viewer);
        $unread = $this->unread($viewer)->select('leads.id')
            ->selectRaw('? as viewer_id', [$viewer->getKey()]);

        $unread->getConnection()->table(self::TABLE)
            ->insertOrIgnoreUsing(['lead_id', $column], $unread->toBase());
    }

    /** @return Builder<Lead> */
    private function unread(User|Corretor $viewer): Builder
    {
        $query = Lead::query()->createdThroughSystem();

        if ($viewer instanceof User) {
            $query->where('leads.company_id', $viewer->company_id);
        }

        return $query->whereNotExists(function (QueryBuilder $reads) use ($viewer): void {
            $reads->from(self::TABLE)
                ->whereColumn('lead_id', 'leads.id')
                ->where($this->viewerColumn($viewer), $viewer->getKey());
        });
    }

    private function viewerColumn(User|Corretor $viewer): string
    {
        return $viewer instanceof Corretor ? 'corretor_id' : 'user_id';
    }
}
