<?php

declare(strict_types=1);

namespace Andre\AiGateway\Models;

use Andre\AiGateway\Database\Factories\AiIntegrationFactory;
use Andre\AiGateway\Support\Schema as GatewaySchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AI integration registry row.
 *
 * One row per use case (a slug + provider + guardrails). The editable surface
 * — prompt template, models, params, server tools — lives on
 * {@see AiIntegrationVersion} so admins iterate without losing history.
 *
 * Backward-compat accessors proxy `models`, `system_prompt`, `default_params`,
 * `prompt_args`, and `server_tools` to the active version so call sites can
 * read them off the integration directly.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property ?string $description
 * @property bool $is_active
 * @property string $provider
 * @property string $visibility
 * @property ?int $rate_limit_per_minute
 * @property ?float $max_daily_cost_usd
 * @property ?AiIntegrationVersion $activeVersion
 */
class AiIntegration extends Model
{
    /** @use HasFactory<AiIntegrationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'is_active',
        'provider',
        'visibility',
        'supports_vision',
        'supports_tools',
        'rate_limit_per_minute',
        'max_daily_cost_usd',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'supports_vision' => 'boolean',
        'supports_tools' => 'boolean',
        'rate_limit_per_minute' => 'integer',
        'max_daily_cost_usd' => 'float',
    ];

    public function getConnectionName(): ?string
    {
        return GatewaySchema::connection();
    }

    public function getTable(): string
    {
        return GatewaySchema::table('integrations');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function newFactory(): AiIntegrationFactory
    {
        return AiIntegrationFactory::new();
    }

    // --- relations -----------------------------------------------------------

    public function versions(): HasMany
    {
        return $this->hasMany(AiIntegrationVersion::class, 'ai_integration_id')
            ->orderByDesc('version_number');
    }

    public function activeVersion(): HasOne
    {
        return $this->hasOne(AiIntegrationVersion::class, 'ai_integration_id')
            ->where('is_active', true);
    }

    public function invocations(): HasMany
    {
        return $this->hasMany(AiInvocation::class, 'ai_integration_id');
    }

    // --- scopes --------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Reachable over the HTTP API. */
    public function scopePublic(Builder $query): Builder
    {
        return $query->whereIn('visibility', config('ai-gateway.api.public_visibilities', ['public', 'both']));
    }

    public function isPubliclyInvocable(): bool
    {
        return in_array($this->visibility, config('ai-gateway.api.public_visibilities', ['public', 'both']), true);
    }

    // --- active-version proxies ---------------------------------------------

    /** @return array<int,string> */
    public function getModelsAttribute(): array
    {
        $models = $this->activeVersion?->models;

        return is_array($models) ? $models : [];
    }

    /** @return array<string,mixed> */
    public function getDefaultParamsAttribute(): array
    {
        $params = $this->activeVersion?->default_params;

        return is_array($params) ? $params : [];
    }

    /** @return array<int,array<string,mixed>> */
    public function getPromptArgsAttribute(): array
    {
        $args = $this->activeVersion?->prompt_args;

        return is_array($args) ? $args : [];
    }

    public function getSystemPromptAttribute(): string
    {
        return (string) ($this->activeVersion?->system_prompt ?? '');
    }

    /** @return array<string,mixed>|null */
    public function getServerToolsAttribute(): ?array
    {
        $serverTools = $this->activeVersion?->server_tools;

        return is_array($serverTools) ? $serverTools : null;
    }
}
