<?php

declare(strict_types=1);

namespace Andre\AiGateway\Models;

use Andre\AiGateway\Support\Schema as GatewaySchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One telemetry row per gateway call — success and failure both. Insert-only
 * (no updated_at). The cost-limit guard sums `cost_usd` over a rolling window
 * from this table.
 *
 * @property int $id
 * @property ?int $ai_integration_id
 * @property string $caller_type
 * @property ?string $caller_id
 * @property ?string $model_used
 * @property ?float $cost_usd
 * @property string $status
 */
class AiInvocation extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ai_integration_id',
        'integration_slug_snapshot',
        'caller_type',
        'caller_id',
        'model_requested',
        'model_used',
        'attempts',
        'prompt_tokens',
        'completion_tokens',
        'cached_tokens',
        'citation_count',
        'cost_usd',
        'latency_ms',
        'status',
        'error_class',
        'error_message',
        'openrouter_generation_id',
        'request_hash',
        'created_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'cached_tokens' => 'integer',
        'citation_count' => 'integer',
        'cost_usd' => 'float',
        'latency_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return GatewaySchema::connection();
    }

    public function getTable(): string
    {
        return GatewaySchema::table('invocations');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(AiIntegration::class, 'ai_integration_id');
    }
}
