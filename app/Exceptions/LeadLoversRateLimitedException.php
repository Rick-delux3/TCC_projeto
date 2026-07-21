<?php

namespace App\Exceptions;

use RuntimeException;

final class LeadLoversRateLimitedException extends RuntimeException
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public readonly ?int $retryAfter = null,
        public readonly bool $cloudflareBlocked = false,
    ) {
        parent::__construct(
            'A API da LeadLovers limitou temporariamente as requisições.'
        );
    }
}
