<?php

namespace App\Services\Insurance\Providers;

use InvalidArgumentException;

class InsuranceProviderResolver
{
    public function __construct(
        private PottencialInsuranceProvider $pottencialProvider,
        private TooInsuranceProvider $tooProvider,
    ) {}

    public function availableProviders(): array
    {
        return [
            'pottencial',
            'too'
        ];
    }

    public function resolve(string $provider): InsuranceProviderInterface
    {
        return match ($provider) {
            'pottencial' => $this->pottencialProvider,
            'too' => $this->tooProvider,

            default => throw new InvalidArgumentException("Provider inválido: {$provider}"),
        };
    }
}