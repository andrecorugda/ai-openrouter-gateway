<?php

declare(strict_types=1);

namespace Andre\AiGateway\Services;

use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Models\AiIntegrationVersion;
use Illuminate\Support\Facades\DB;

/**
 * Owns the version lifecycle: minting new versions on edit, and activating a
 * version (exactly one active per integration, flipped inside a transaction).
 */
class AiIntegrationService
{
    public function __construct(private readonly AiIntegrationResolver $resolver) {}

    /**
     * Persist a brand-new version for an integration and (optionally) activate
     * it. Version numbers are monotonic per integration.
     *
     * @param  array<string,mixed>  $attributes  system_prompt, models, default_params, prompt_args, server_tools, notes
     */
    public function saveVersion(AiIntegration $integration, array $attributes, bool $activate = true, ?int $userId = null): AiIntegrationVersion
    {
        return DB::connection($integration->getConnectionName())->transaction(function () use ($integration, $attributes, $activate, $userId) {
            $next = (int) $integration->versions()->max('version_number') + 1;

            /** @var AiIntegrationVersion $version */
            $version = $integration->versions()->create([
                'version_number' => $next,
                'is_active' => false,
                'system_prompt' => $attributes['system_prompt'] ?? '',
                'system_prompt_cacheable' => $attributes['system_prompt_cacheable'] ?? true,
                'models' => array_values($attributes['models'] ?? []),
                'default_params' => $attributes['default_params'] ?? null,
                'prompt_args' => array_values($attributes['prompt_args'] ?? []),
                'server_tools' => $attributes['server_tools'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $userId,
            ]);

            if ($activate) {
                $this->activate($version);
            }

            return $version;
        });
    }

    /**
     * Make $version the single active version for its integration.
     */
    public function activate(AiIntegrationVersion $version): void
    {
        DB::connection($version->getConnectionName())->transaction(function () use ($version) {
            AiIntegrationVersion::query()
                ->where('ai_integration_id', $version->ai_integration_id)
                ->where('id', '!=', $version->id)
                ->update(['is_active' => false]);

            $version->forceFill(['is_active' => true])->save();
        });

        $integration = $version->integration;
        if ($integration instanceof AiIntegration) {
            $this->resolver->forgetCache($integration->slug);
        }
    }
}
