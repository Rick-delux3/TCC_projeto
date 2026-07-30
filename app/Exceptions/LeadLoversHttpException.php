<?php

namespace App\Exceptions;

use RuntimeException;

class LeadLoversHttpException extends RuntimeException
{
    public function __construct(
        public readonly ?int $statusCode,
        string $operation,
        public readonly ?string $safeApiMessage = null,
    ) {
        $status = $statusCode !== null
            ? (string) $statusCode
            : 'desconhecido';

        $message = rtrim($operation, '.')
            .'. HTTP '.$status.'.';

        if (
            is_string($safeApiMessage)
            && trim($safeApiMessage) !== ''
        ) {
            $message .= ' Mensagem: '.$safeApiMessage;
        }

        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        if ($this->statusCode === null) {
            return true;
        }

        return in_array(
            $this->statusCode,
            [408, 425, 429],
            true
        ) || $this->statusCode >= 500;
    }
}