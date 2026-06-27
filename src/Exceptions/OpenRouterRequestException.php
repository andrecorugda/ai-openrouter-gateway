<?php

declare(strict_types=1);

namespace Andre\AiGateway\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Transport / upstream failure talking to OpenRouter.
 *
 * Carries whatever provider context survived the failure — HTTP status,
 * generation id, decoded error body — so the gateway can still salvage cost
 * forensics (OpenRouter may bill a generation that then errors downstream).
 */
class OpenRouterRequestException extends RuntimeException
{
    /**
     * @param  array<string,mixed>|null  $body
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?string $generationId = null,
        private readonly ?array $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function generationId(): ?string
    {
        return $this->generationId;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function body(): ?array
    {
        return $this->body;
    }
}
