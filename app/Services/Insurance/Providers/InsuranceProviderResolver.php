<?php

namespace App\Services\Insurance\Providers;

use InvalidArgumentException;

class InsuranceProviderResolver
{
    public function __construct(
        private PottencialInsuranceProvider $pottencialProvider,
    ) {}

    public function availableProviders(): array
    {
        return [
            'pottencial',
            // 'porto',
            // 'tokio',
            // 'too',
        ];
    }

    public function resolve(string $provider): InsuranceProviderInterface
    {
        return match ($provider) {
            'pottencial' => $this->pottencialProvider,

            default => throw new InvalidArgumentException("Provider inválido: {$provider}"),
        };
    }
}