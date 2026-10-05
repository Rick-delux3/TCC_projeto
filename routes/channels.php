<?php

use App\Models\Corretor;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('companies.{companyId}.leads.{leadId}.analyses',
    function (User $user, int $companyId, int $leadId): bool {
        $lead = Lead::query()->find($leadId);

        return $companyId > 0 && (int) $user->company_id === $companyId
            && session('2fa_passed') === true
            && $lead !== null && Gate::forUser($user)->allows('viewAnalyses', $lead);
    }, ['guards' => ['web']]
);

Broadcast::channel('admins.leads.{leadId}.analyses',
    function (Corretor $corretor, int $leadId): bool {
        $lead = Lead::query()->find($leadId);

        return $corretor->isActive()
            && ($corretor->hasVerifiedFirstLogin() || session('admin_2fa_passed') === true)
            && $lead !== null && Gate::forUser($corretor)->allows('viewAnalyses', $lead);
    }, ['guards' => ['admin']]
);

Broadcast::channel('companies.{companyId}.dashboard', function (User $user, int $companyId): bool {
    $sameCompany = (int) $user->company_id === $companyId;

    $passedTwoFactor = session('2fa_passed') === true;

    return $sameCompany && $passedTwoFactor;
},

    ['guards' => ['web']]
);

Broadcast::channel('admins.dashboard',
    function (Corretor $corretor): bool {
        $passedTwoFactor =
            $corretor->hasVerifiedFirstLogin() || session('admin_2fa_passed') === true;

        return $passedTwoFactor && Gate::forUser($corretor)->allows('access-dashboard');
    },
    ['guards' => ['admin']]
);
