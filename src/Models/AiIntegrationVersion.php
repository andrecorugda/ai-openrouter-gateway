<?php

declare(strict_types=1);

namespace Andre\AiGateway\Models;

use Andre\AiGateway\Services\AiIntegrationService;
use Andre\AiGateway\Support\Schema as GatewaySchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable-ish snapshot of an integration's editable surface. Saving an
 * edit mints a new version; activating one flips `is_active` (exactly one
 * active per integration, enforced by {@see AiIntegrationService}).
 *
 * @property int $id
 * @property int $ai_integration_id
 * @property int $version_number
 * @property bool $is_active
 * @property ?string $system_prompt
 * @property bool $system_prompt_cacheable
 * @property array<int,string> $models
 * @property array<string,mixed>|null $default_params
 * @property array<int,array<string,mixed>>|null $prompt_args
 * @property array<string,mixed>|null $server_tools
 */
class AiIntegrationVersion extends Model
{
    protected $fillable = [
        'ai_integration_id',
        'version_number',
        'is_active',
        'system_prompt',
        'system_prompt_cacheable',
        'models',
        'default_params',
        'prompt_args',
        'server_tools',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'system_prompt_cacheable' => 'boolean',
        'version_number' => 'integer',
        'models' => 'array',
        'default_params' => 'array',
        'prompt_args' => 'array',
        'server_tools' => 'array',
    ];

    public function getConnectionName(): ?string
    {
        return GatewaySchema::connection();
    }

    public function getTable(): string
    {
        return GatewaySchema::table('integration_versions');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(AiIntegration::class, 'ai_integration_id');
    }
}
