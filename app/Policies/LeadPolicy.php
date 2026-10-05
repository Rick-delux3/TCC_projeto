<?php

namespace App\Policies;

use App\Models\Corretor;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class LeadPolicy
{
    public function viewAnalyses(User|Corretor $user, Lead $lead): bool
    {
        if ($user instanceof Corretor) {
            return Gate::forUser($user)->allows('view-analyses');
        }

        return (int) $user->company_id > 0
            && (int) $lead->company_id === (int) $user->company_id;
    }

    public function requestAnalysis(User|Corretor $user, Lead $lead): bool
    {
        return $this->viewAnalyses($user, $lead)
            && (! $user instanceof Corretor || Gate::forUser($user)->allows('create-analysis'));
    }

    public function reanalyzeWithChanges(User|Corretor $user, Lead $lead): bool
    {
        return $this->requestAnalysis($user, $lead)
            && (! $user instanceof Corretor || Gate::forUser($user)->allows('edit-leads'));
    }
}
