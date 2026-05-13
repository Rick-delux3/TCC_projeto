<?php

namespace App\Services\Insurance;

use App\Models\Lead;

class PottencialProductResolver
{
    public function productForLead(Lead $lead): string
    {
        return match ($lead->tipo_solicitante) {
            'imobiliaria_cadastrada',
            'imobiliaria_nao_cadastrada' => 'imobiliario',

            'locatario',
            'locador' => 'residencial',

            default => 'residencial',
        };
    }

    public function shouldUseRealEstateEndpoint(Lead $lead): bool
    {
        return $this->productForLead($lead) === 'imobiliario';
    }

    public function shouldUseResidentialEndpoint(Lead $lead): bool
    {
        return $this->productForLead($lead) === 'residencial';
    }
}