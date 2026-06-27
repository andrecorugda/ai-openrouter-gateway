<?php

declare(strict_types=1);

namespace Andre\AiGateway\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runtime, admin-editable settings backed by the `ai_gateway_settings` table.
 *
 * These let an operator flip behaviour from the UI without a redeploy:
 *   - api_enabled            (bool)   — master switch for the HTTP API layer
 *   - prompt_builder_enabled (bool)   — show/hide the AI prompt builder
 *   - prompt_builder_model   (string) — model the prompt builder uses
 *
 * Every key falls back to its config default when unset, so the package works
 * before the settings table is even populated. Reads are request-memoized.
 *
 * @phpstan-type SettingValue scalar|array<mixed>|null
 */
final class Settings
{
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /**
     * Config fallback for each runtime-overridable setting.
     *
     * @return array<string,mixed>
     */
    private static function defaults(): array
    {
        return [
            'api_enabled' => (bool) config('ai-gateway.api.enabled', true),
            'prompt_builder_enabled' => (bool) config('ai-gateway.prompt_builder.enabled', true),
            'prompt_builder_model' => (string) config('ai-gateway.prompt_builder.model', 'anthropic/claude-haiku-4.5'),
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $defaults = self::defaults();
        $fallback = $default ?? ($defaults[$key] ?? null);

        return self::all()[$key] ?? $fallback;
    }

    public static function bool(string $key): bool
    {
        return (bool) self::get($key);
    }

    public static function string(string $key): string
    {
        return (string) self::get($key);
    }

    public static function set(string $key, mixed $value): void
    {
        self::query()->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
        );

        self::$cache = null;
    }

    /**
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = [];

        try {
            foreach (self::query()->get() as $row) {
                $stored[$row->key] = json_decode((string) $row->value, true);
            }
        } catch (Throwable) {
            // Table not migrated yet — fall back to config defaults silently.
            $stored = [];
        }

        return self::$cache = array_merge(self::defaults(), $stored);
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function query(): Builder
    {
        return DB::connection(Schema::connection())->table(Schema::table('settings'));
    }
}
