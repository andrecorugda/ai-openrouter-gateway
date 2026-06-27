<?php

declare(strict_types=1);

namespace Andre\AiGateway\Support;

/**
 * Resolves the package's configurable connection + table names. Used by the
 * migrations and models so a host app can relocate / rename everything from
 * config without editing package files.
 */
final class Schema
{
    public static function connection(): ?string
    {
        /** @var string|null */
        return config('ai-gateway.database.connection');
    }

    /**
     * Map a logical table key (integrations|integration_versions|invocations|settings)
     * to its configured physical name.
     */
    public static function table(string $key): string
    {
        /** @var array<string,string> $tables */
        $tables = config('ai-gateway.database.tables', []);

        return $tables[$key] ?? $key;
    }
}
