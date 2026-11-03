<?php

namespace App\Services\Insurance\Providers;

use InvalidArgumentException;

class InsuranceProviderResolver
{
    public function __construct(
        private PottencialInsuranceProvider $pottencialProvider,
        private TooInsuranceProvider $tooProvider,
        private TokioInsuranceProvider $tokioProvider,
    ) {}

    public function availableProviders(): array
    {
        $this->ensureAnalysisEnabled();

        return array_values(array_filter([
            'pottencial',
            'too',
            'tokio',
        ], fn (string $provider) => config("services.{$provider}.enabled", false)));
    }

    public function resolve(string $provider): InsuranceProviderInterface
    {
        $this->ensureAnalysisEnabled();

        if (! config("services.{$provider}.enabled", false)) {
            throw new \LogicException("O provider {$provider} está desativado.");
        }

        return match ($provider) {
            'pottencial' => $this->pottencialProvider,
            'too' => $this->tooProvider,
            'tokio' => $this->tokioProvider,

            default => throw new InvalidArgumentException("Provider inválido: {$provider}"),
        };
    }

    private function ensureAnalysisEnabled(): void
    {
        if (! config('features.insurance_analysis.enabled', false)) {
            throw new \LogicException(
                'O sistema de análises está temporariamente desativado.'
            );
        }
    }
}
