<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

class RetryLeadLoversOutageJob extends SendLeadToLeadLoversJob implements ShouldBeUniqueUntilProcessing
{
    protected bool $recoveringOutage = true;

    public function __construct(int $leadId, bool $afterEdit = false)
    {
        parent::__construct($leadId);
        $this->afterEdit = $afterEdit;
    }

    public function uniqueId(): string
    {
        return ($this->afterEdit ? 'leadlovers-outage-edit:' : 'leadlovers-outage:').$this->leadId;
    }
}
