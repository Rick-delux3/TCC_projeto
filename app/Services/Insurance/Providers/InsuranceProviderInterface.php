<?php

namespace App\Services\Insurance\Providers;

use App\Models\InsuranceExecution;

interface InsuranceProviderInterface
{
    public function name(): string;

    public function requestAnalysis(InsuranceExecution $analysis, string $attemptId): array;

    public function requestReanalysis(
        InsuranceExecution $analysis,
        string $attemptId,
        array $options = []
    ): array;

    public function getStatus(InsuranceExecution $analysis): array;
}
