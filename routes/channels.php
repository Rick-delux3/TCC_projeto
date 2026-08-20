<?php

use Illuminate\Support\Facades\Broadcast;
use App\Models\Corretor;
use App\Models\User;
use Illuminate\Support\Facades\Gate;


Broadcast::channel('companies.{companyId}.dashboard', function (User $user, int $companyId): bool {
    $sameCompany =
            (int) $user->company_id === $companyId
            && (int) session('company_id') === $companyId;

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
