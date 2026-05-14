<?php

namespace App\Services\Insurance\Providers;

use App\Models\InsuranceAnalysis;

interface InsuranceProviderInterface
{
    public function name(): string;

    public function requestAnalysis(InsuranceAnalysis $analysis): array;

    public function getStatus(InsuranceAnalysis $analysis): array;
}
