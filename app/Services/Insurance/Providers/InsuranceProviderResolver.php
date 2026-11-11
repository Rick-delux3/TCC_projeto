<?php

namespace App\Services\Insurance\Providers;

use InvalidArgumentException;

class InsuranceProviderResolver
{
    /** Seguradoras integradas, na ordem de criação das análises. */
    public const PROVIDERS = ['pottencial', 'too', 'tokio', 'porto'];

    /**
     * Fonte única da flag por seguradora (`{PROVIDER}_ENABLED` no .env),
     * usada na criação das análises, no painel e nas ações por companhia.
     */
    public static function providerEnabled(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true)
            && filter_var(config("services.{$provider}.enabled", false), FILTER_VALIDATE_BOOL);
    }

    /**
     * Companhias que aceitam reanálise (novo cálculo após aprovação/recusa).
     * Pottencial e Too ficam fora: vinculam o objeto de risco à cotação anterior.
     * Deve acompanhar os providers que implementam ReanalyzesInsuranceQuotes.
     */
    public const REANALYSIS_PROVIDERS = ['tokio', 'porto'];

    /** Fonte única da permissão de reanálise, usada nos services, jobs e painel. */
    public static function supportsReanalysis(string $provider): bool
    {
        return in_array(mb_strtolower(trim($provider)), self::REANALYSIS_PROVIDERS, true);
    }

    /** @return list<string> */
    public static function enabledProviders(): array
    {
        return array_values(array_filter(self::PROVIDERS, self::providerEnabled(...)));
    }

    public function __construct(
        private PottencialInsuranceProvider $pottencialProvider,
        private TooInsuranceProvider $tooProvider,
        private TokioInsuranceProvider $tokioProvider,
        private PortoInsuranceProvider $portoProvider,
    ) {}

    public function availableProviders(): array
    {
        $this->ensureAnalysisEnabled();

        return self::enabledProviders();
    }

    public function resolve(string $provider): InsuranceProviderInterface
    {
        $this->ensureAnalysisEnabled();

        if (! self::providerEnabled($provider)) {
            throw new \LogicException("O provider {$provider} está desativado.");
        }

        return match ($provider) {
            'pottencial' => $this->pottencialProvider,
            'too' => $this->tooProvider,
            'tokio' => $this->tokioProvider,
            'porto' => $this->portoProvider,

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
