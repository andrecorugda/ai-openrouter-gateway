<?php

declare(strict_types=1);

namespace Andre\AiGateway\Tests\Fixtures;

/** An invokable gate, the config:cache-safe form of filament.authorize. */
class AllowsAdmins
{
    public function __invoke(?User $user): bool
    {
        return $user?->email === 'admin@example.test';
    }
}
