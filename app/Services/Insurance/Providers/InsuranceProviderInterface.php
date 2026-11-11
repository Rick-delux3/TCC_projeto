<?php

namespace App\Services\Insurance\Providers;

use App\Models\InsuranceExecution;

interface InsuranceProviderInterface
{
    public function name(): string;

    public function requestAnalysis(InsuranceExecution $analysis, string $attemptId): array;

    /** Reanálise é opcional: somente providers que implementam ReanalyzesInsuranceQuotes. */
    public function getStatus(InsuranceExecution $analysis): array;
}
