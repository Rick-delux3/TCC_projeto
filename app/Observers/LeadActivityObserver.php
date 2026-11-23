<?php

namespace App\Observers;

use App\Events\DashboardActivityChanged;
use App\Models\Lead;

class LeadActivityObserver
{
    public function created(Lead $lead): void
    {
        if (in_array($lead->origem, Lead::SYSTEM_ORIGINS, true)) {
            DashboardActivityChanged::dispatch(
                'lead',
                (int) $lead->id,
                $lead->company_id !== null ? (int) $lead->company_id : null,
                'lead.created',
            );
        }
    }
}
