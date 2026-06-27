<?php

declare(strict_types=1);

namespace Andre\AiGateway\Services;

use Andre\AiGateway\Exceptions\CostLimitExceededException;
use Andre\AiGateway\Exceptions\RateLimitExceededException;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Models\AiInvocation;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Pre-flight guardrails the gateway runs before every dispatch:
 *
 *   - Rate limit  — per integration, per caller, per minute (cache counter).
 *   - Cost limit  — per integration daily USD budget (rolling-window sum of
 *                   cost_usd from ai_invocations).
 *
 * Each integration may set its own ceiling; a blank ceiling falls back to the
 * package config default, and a null default means "unlimited". Both checks
 * can be disabled wholesale via config.
 */
class UsageGuard
{
    /**
     * @throws RateLimitExceededException
     * @throws CostLimitExceededException
     */
    public function assert(AiIntegration $integration, string $callerType, ?string $callerId): void
    {
        $this->assertRateLimit($integration, $callerType, $callerId);
        $this->assertCostLimit($integration);
    }

    public function assertRateLimit(AiIntegration $integration, string $callerType, ?string $callerId): void
    {
        if (! (bool) config('ai-gateway.rate_limit.enabled', true)) {
            return;
        }

        $limit = $integration->rate_limit_per_minute ?? config('ai-gateway.rate_limit.default_per_minute');
        if ($limit === null || (int) $limit <= 0) {
            return;
        }
        $limit = (int) $limit;

        $bucket = (int) floor(now()->timestamp / 60);
        $callerKey = $callerType.':'.($callerId ?? 'anon');
        $key = "ai-gateway:rate:{$integration->id}:{$callerKey}:{$bucket}";

        $cache = $this->cache();
        $current = (int) $cache->get($key, 0);

        if ($current >= $limit) {
            throw new RateLimitExceededException(
                "Rate limit of {$limit}/min exceeded for integration '{$integration->slug}'.",
                retryAfterSeconds: 60 - (now()->timestamp % 60),
            );
        }

        // Increment + (re)assert a 60s TTL so the bucket self-expires.
        $cache->put($key, $current + 1, 60);
    }

    public function assertCostLimit(AiIntegration $integration): void
    {
        if (! (bool) config('ai-gateway.cost_limit.enabled', true)) {
            return;
        }

        $cap = $integration->max_daily_cost_usd ?? config('ai-gateway.cost_limit.default_daily_usd');
        if ($cap === null || (float) $cap <= 0) {
            return;
        }
        $cap = (float) $cap;

        $spent = (float) $this->spentInWindow($integration);

        if ($spent >= $cap) {
            throw new CostLimitExceededException(
                sprintf(
                    "Daily cost limit of \$%.2f reached for integration '%s' (spent \$%.4f).",
                    $cap,
                    $integration->slug,
                    $spent,
                ),
                spentUsd: $spent,
                capUsd: $cap,
            );
        }
    }

    /**
     * Sum cost_usd for an integration over the rolling cost window.
     */
    public function spentInWindow(AiIntegration $integration): float
    {
        /** @var class-string<AiInvocation> $model */
        $model = config('ai-gateway.models.invocation', AiInvocation::class);
        $hours = (int) config('ai-gateway.cost_limit.window_hours', 24);

        return (float) $model::query()
            ->where('ai_integration_id', $integration->id)
            ->where('created_at', '>=', now()->subHours($hours))
            ->sum('cost_usd');
    }

    private function cache(): Repository
    {
        $store = config('ai-gateway.cache.store');

        return $store !== null ? Cache::store($store) : Cache::store();
    }
}
