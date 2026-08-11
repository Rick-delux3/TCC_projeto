<?php

namespace App\Exceptions;

use RuntimeException;

final class LeadLoversApiException extends RuntimeException
{
    public readonly ?int $httpStatus;

    public readonly bool $transient;

    public static function make(
        ?int $statusCode,
        ?string $errorCode,
        string $safeReason,
        bool $isTransient,
        ?int $retryAfterSeconds = null,
        bool $isConfigurationError = false,
    ): self {
        $previousSetting = ini_set('zend.exception_ignore_args', '1');

        try {
            return new self(
                statusCode: $statusCode,
                errorCode: $errorCode,
                safeReason: $safeReason,
                isTransient: $isTransient,
                retryAfterSeconds: $retryAfterSeconds,
                isConfigurationError: $isConfigurationError,
            );
        } finally {
            if ($previousSetting !== false) {
                ini_set('zend.exception_ignore_args', $previousSetting);
            }
        }
    }

    private function __construct(
        public readonly ?int $statusCode,
        public readonly ?string $errorCode,
        public readonly string $safeReason,
        public readonly bool $isTransient,
        public readonly ?int $retryAfterSeconds,
        public readonly bool $isConfigurationError,
    ) {
        $this->httpStatus = $statusCode;
        $this->transient = $isTransient;

        $message = 'Falha na API da LeadLovers.';

        if ($statusCode !== null) {
            $message .= ' HTTP '.$statusCode.'.';
        }

        if ($errorCode !== null) {
            $message .= ' Código '.$errorCode.'.';
        }

        $message .= ' '.$safeReason;

        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return $this->isTransient;
    }
}
