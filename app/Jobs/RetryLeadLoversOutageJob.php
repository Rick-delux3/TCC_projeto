<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

class RetryLeadLoversOutageJob extends SendLeadToLeadLoversJob implements ShouldBeUniqueUntilProcessing
{
    protected bool $recoveringOutage = true;

    public function uniqueId(): string
    {
        return 'leadlovers-outage:'.$this->leadId;
    }
}
