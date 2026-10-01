<?php

namespace App\Services\Insurance;

use App\Models\Lead;

class AnalysisResultRecipients
{
    /** @return array{to: list<string>, cc: list<string>} */
    public function resolve(Lead $lead): array
    {
        $lead->loadMissing(['company.setores', 'imobiliariaInformada', 'locador']);

        $emails = match ($lead->tipo_solicitante) {
            'imobiliaria_cadastrada' => array_merge(
                [$lead->company?->email],
                $lead->company?->setores->pluck('email')->all() ?? [],
            ),
            'imobiliaria_nao_cadastrada' => [$lead->imobiliariaInformada?->responsavel_preenchimento],
            'locador' => [$lead->locador?->email],
            'locatario' => [$lead->email],
            default => [],
        };

        return [
            'to' => collect($emails)
                ->filter(fn ($email) => is_string($email))
                ->map(fn (string $email): string => mb_strtolower(trim($email)))
                ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
                ->unique()->values()->all(),
            'cc' => [],
        ];
    }
}
