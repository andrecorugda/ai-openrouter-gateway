<?php

declare(strict_types=1);

namespace Andre\AiGateway\Services;

use Andre\AiGateway\Models\AiIntegration;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Cached lookup of integration rows by slug. The gateway calls this on every
 * invocation, so the DB round-trip is memoized through the configured cache
 * store. TTL is short enough that admin edits propagate quickly.
 */
class AiIntegrationResolver
{
    /**
     * Returns null when the slug is unregistered, the row is inactive, or it
     * has no active version — callers decide whether that maps to 404 or 409.
     */
    public function resolve(string $slug): ?AiIntegration
    {
        return $this->cache()->remember(
            $this->cacheKey($slug),
            (int) config('ai-gateway.cache.ttl_seconds', 60),
            function () use ($slug): ?AiIntegration {
                /** @var class-string<AiIntegration> $model */
                $model = config('ai-gateway.models.integration', AiIntegration::class);

                $integration = $model::query()
                    ->with('activeVersion')
                    ->where('slug', $slug)
                    ->active()
                    ->first();

                if ($integration === null || $integration->activeVersion === null) {
                    return null;
                }

                return $integration;
            },
        );
    }

    public function forgetCache(string $slug): void
    {
        $this->cache()->forget($this->cacheKey($slug));
    }

    private function cache(): Repository
    {
        $store = config('ai-gateway.cache.store');

        return $store !== null ? Cache::store($store) : Cache::store();
    }

    private function cacheKey(string $slug): string
    {
        return config('ai-gateway.cache.prefix', 'ai-gateway.integration.').$slug;
    }
}
