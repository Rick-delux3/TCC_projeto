<?php

namespace App\Services\Insurance;

final class ProviderAnalysisStatus
{
    public static function fromProviderStatus(?string $status): string
    {
        return match (mb_strtolower(trim((string) $status))) {
            'approved' => 'approved',
            'denied', 'rejected', 'refused', 'recused' => 'rejected',
            'underanalysis', 'under_analysis', 'pending' => 'processing',
            'manual_review', 'preapproved' => 'manual_review',
            default => 'failed',
        };
    }

    public static function result(string $status): ?string
    {
        return in_array($status, ['approved', 'rejected', 'manual_review'], true)
            ? $status
            : null;
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array($status, ['approved', 'rejected', 'failed'], true);
    }

    public static function errorMessage(string $status): ?string
    {
        return $status === 'failed'
            ? 'A companhia não retornou um status de análise reconhecido. Consulte o retorno antes de repetir a solicitação.'
            : null;
    }
}
