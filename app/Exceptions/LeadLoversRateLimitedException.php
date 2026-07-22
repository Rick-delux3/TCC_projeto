<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class LeadLoversRateLimitedException extends RuntimeException
{
    public static function fromResponse(Response $response): self
    {
        $retryAfter = trim((string) $response->header('Retry-After'));
        $defaultDelay = max(
            1,
            (int) config('services.leadlovers.rate_limit_retry_seconds', 60)
        );
        $maximumDelay = max(
            $defaultDelay,
            (int) config('services.leadlovers.rate_limit_max_retry_seconds', 900)
        );

        if ($retryAfter !== '' && ctype_digit($retryAfter)) {
            $delay = (int) $retryAfter;
        } elseif ($retryAfter !== '' && ($retryAt = strtotime($retryAfter)) !== false) {
            $delay = max(1, $retryAt - time());
        } else {
            $delay = $defaultDelay;
        }

        return new self(
            retryAfter: min(max(1, $delay), $maximumDelay),
            cloudflareBlocked: str_contains(
                mb_strtolower($response->body()),
                'error code: 1015'
            ),
        );
    }

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
