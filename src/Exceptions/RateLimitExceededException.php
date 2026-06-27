<?php

declare(strict_types=1);

namespace Andre\AiGateway\Exceptions;

use RuntimeException;

/**
 * Thrown when a caller exceeds an integration's per-minute request ceiling.
 * Controllers map this to HTTP 429.
 */
class RateLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message = 'AI gateway rate limit exceeded',
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
