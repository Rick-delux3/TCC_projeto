<?php

namespace App\Support;

use App\Models\Lead;
use App\Rules\CpfOrCnpj;
use Carbon\CarbonImmutable;

class CompanyConstitution
{
    public const REJECTION_MESSAGE = 'Empresa com menos de dois anos não tem aceitação. Por favor fazer análise com CPF.';

    /** @return array{tipo_pessoa: ?string, empresa_constituida: ?string, data_fundacao_calculada: ?string} */
    public static function attributes(?string $document, ?string $rentalType, ?string $answer, ?Lead $existing = null): array
    {
        $document = CpfOrCnpj::normalize($document);
        $company = is_string($document) && preg_match('/^[0-9]{14}$/D', $document) === 1;
        $person = is_string($document) && preg_match('/^[0-9]{11}$/D', $document) === 1;
        $attributes = [
            'tipo_pessoa' => $company ? 'J' : ($person ? 'F' : null),
            'empresa_constituida' => null,
            'data_fundacao_calculada' => null,
        ];

        if (! $company || $rentalType !== 'comercial') {
            return $attributes;
        }

        if ($answer === 'S') {
            $attributes['empresa_constituida'] = 'S';
            $attributes['data_fundacao_calculada'] = CarbonImmutable::today(config('simulation.company_constitution.timezone'))
                ->subYearsNoOverflow((int) config('simulation.company_constitution.reference_years'))
                ->toDateString();
        } elseif ($answer === null && $existing?->tipo_locacao?->value === 'comercial'
            && $existing->rentalApplicantDocument() === $document) {
            $attributes['empresa_constituida'] = $existing->empresa_constituida;
            $attributes['data_fundacao_calculada'] = $existing->data_fundacao_calculada?->toDateString();
        }

        return $attributes;
    }
}
