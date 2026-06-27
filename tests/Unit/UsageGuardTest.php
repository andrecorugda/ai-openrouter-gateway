<?php

declare(strict_types=1);

use Andre\AiGateway\Exceptions\CostLimitExceededException;
use Andre\AiGateway\Exceptions\RateLimitExceededException;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Models\AiInvocation;
use Andre\AiGateway\Services\UsageGuard;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    // Array cache + the in-memory sqlite DB are configured by the TestCase.
    $this->guard = app(UsageGuard::class);
});

// ---------------------------------------------------------------------------
// assertRateLimit
// ---------------------------------------------------------------------------

it('allows exactly N calls within the window then throws', function (): void {
    $integration = AiIntegration::factory()->withVersion()->create([
        'rate_limit_per_minute' => 3,
    ]);

    // First three are under the ceiling.
    for ($i = 0; $i < 3; $i++) {
        $this->guard->assertRateLimit($integration, 'api', 'token:1');
    }

    // The fourth trips the limit.
    expect(fn () => $this->guard->assertRateLimit($integration, 'api', 'token:1'))
        ->toThrow(RateLimitExceededException::class);
});

it('counts callers independently', function (): void {
    $integration = AiIntegration::factory()->withVersion()->create([
        'rate_limit_per_minute' => 1,
    ]);

    $this->guard->assertRateLimit($integration, 'api', 'token:1');

    // A different caller still has its own fresh bucket.
    $this->guard->assertRateLimit($integration, 'api', 'token:2');
})->throwsNoExceptions();

it('does not rate-limit when the integration leaves the ceiling blank and no default is set', function (): void {
    config()->set('ai-gateway.rate_limit.default_per_minute', null);

    $integration = AiIntegration::factory()->withVersion()->create([
        'rate_limit_per_minute' => null,
    ]);

    for ($i = 0; $i < 50; $i++) {
        $this->guard->assertRateLimit($integration, 'api', 'token:1');
    }
})->throwsNoExceptions();

// ---------------------------------------------------------------------------
// assertCostLimit
// ---------------------------------------------------------------------------

it('throws once summed cost in the window meets the cap', function (): void {
    $integration = AiIntegration::factory()->withVersion()->create([
        'max_daily_cost_usd' => 1.00,
    ]);

    // Two fresh rows summing to exactly the cap → spent >= cap.
    seedInvocation($integration, 0.60);
    seedInvocation($integration, 0.40);

    expect(fn () => $this->guard->assertCostLimit($integration))
        ->toThrow(CostLimitExceededException::class);
});

it('does not throw while spending stays under the cap', function (): void {
    $integration = AiIntegration::factory()->withVersion()->create([
        'max_daily_cost_usd' => 1.00,
    ]);

    seedInvocation($integration, 0.30);
    seedInvocation($integration, 0.50);

    $this->guard->assertCostLimit($integration);
})->throwsNoExceptions();

it('ignores invocation rows older than the cost window', function (): void {
    config()->set('ai-gateway.cost_limit.window_hours', 24);

    $integration = AiIntegration::factory()->withVersion()->create([
        'max_daily_cost_usd' => 1.00,
    ]);

    // Stale spend well over the cap, but outside the 24h window → ignored.
    seedInvocation($integration, 5.00, now()->subHours(48));

    $this->guard->assertCostLimit($integration);
})->throwsNoExceptions();

it('surfaces spent and cap on the thrown exception', function (): void {
    $integration = AiIntegration::factory()->withVersion()->create([
        'max_daily_cost_usd' => 2.00,
    ]);

    seedInvocation($integration, 2.50);

    try {
        $this->guard->assertCostLimit($integration);
        test()->fail('Expected CostLimitExceededException.');
    } catch (CostLimitExceededException $e) {
        expect($e->capUsd)->toBe(2.00)
            ->and($e->spentUsd)->toBeGreaterThanOrEqual(2.50);
    }
});

/**
 * Insert a telemetry row directly so the cost guard has data to sum.
 */
function seedInvocation(AiIntegration $integration, float $cost, ?Carbon $at = null): void
{
    AiInvocation::create([
        'ai_integration_id' => $integration->id,
        'integration_slug_snapshot' => $integration->slug,
        'caller_type' => 'api',
        'caller_id' => 'token:1',
        'model_used' => 'anthropic/claude-sonnet-4',
        'attempts' => 1,
        'cost_usd' => $cost,
        'status' => 'ok',
        'created_at' => $at ?? now(),
    ]);
}
