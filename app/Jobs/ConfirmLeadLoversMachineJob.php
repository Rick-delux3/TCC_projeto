<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

class ConfirmLeadLoversMachineJob extends SendLeadToLeadLoversJob implements ShouldBeUniqueUntilProcessing
{
    protected bool $confirmingMachine = true;

    public function uniqueId(): string
    {
        return 'leadlovers-confirmation:'.$this->leadId;
    }
}
