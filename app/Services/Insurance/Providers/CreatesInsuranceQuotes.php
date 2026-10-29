<?php

namespace App\Services\Insurance\Providers;

use App\Models\InsuranceExecution;

interface CreatesInsuranceQuotes
{
    /**
     * Cria uma cotação remota sem persistir resultados nem iniciar as demais etapas do pacote.
     *
     * @return array{success: bool, http_status: int|null, endpoint: string, url: string|null, response: array, raw_body: string|null, headers?: array, error?: string}
     */
    public function createQuote(InsuranceExecution $analysis): array;
}
