<?php

namespace App\Services\Insurance\Providers;

use App\Models\InsuranceExecution;

/**
 * Companhias que aceitam reanálise: um novo cálculo da mesma companhia após
 * aprovação/recusa ou com despesas alteradas.
 *
 * Pottencial e Too não implementam este contrato: elas vinculam o objeto de
 * risco à cotação anterior (Pottencial: "The risk object is linked to another
 * quote within 30 days") e não permitem recalcular só as coberturas. A lista
 * em InsuranceProviderResolver::REANALYSIS_PROVIDERS deve acompanhar este tipo.
 */
interface ReanalyzesInsuranceQuotes extends InsuranceProviderInterface
{
    public function requestReanalysis(InsuranceExecution $analysis, string $attemptId, array $options = []): array;
}
