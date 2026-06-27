<?php

declare(strict_types=1);

namespace Andre\AiGateway\Exceptions;

use RuntimeException;

/**
 * Thrown when an integration has already spent its daily USD budget within the
 * rolling cost window. Controllers map this to HTTP 402 (Payment Required).
 */
class CostLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message = 'AI gateway daily cost limit reached',
        public readonly ?float $spentUsd = null,
        public readonly ?float $capUsd = null,
    ) {
        parent::__construct($message);
    }
}
