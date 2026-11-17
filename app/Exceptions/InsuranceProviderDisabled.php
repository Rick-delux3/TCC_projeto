<?php

namespace App\Exceptions;

/**
 * Companhia desligada no .env ({PROVIDER}_ENABLED). É um erro definitivo:
 * nova tentativa não muda a configuração, então os jobs encerram a cotação
 * na hora em vez de passar pelo backoff da fila.
 */
class InsuranceProviderDisabled extends \LogicException
{
    private const LABELS = [
        'pottencial' => 'Pottencial',
        'too' => 'Too Seguros',
        'tokio' => 'Tokio Marine',
        'porto' => 'Porto Seguro',
    ];

    public function __construct(public readonly string $provider)
    {
        parent::__construct("O provider {$provider} está desativado.");
    }

    /** Mensagem gravada no histórico e no error_message da cotação. */
    public function userMessage(): string
    {
        $label = self::LABELS[mb_strtolower($this->provider)] ?? $this->provider;

        return "A companhia {$label} está desativada no sistema. A cotação foi encerrada sem nova tentativa.";
    }
}
