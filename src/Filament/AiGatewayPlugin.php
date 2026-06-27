<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament;

use Andre\AiGateway\Filament\Pages\ApiTokens;
use Andre\AiGateway\Filament\Pages\GeneralSettings;
use Andre\AiGateway\Filament\Resources\AiIntegrationResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Filament plugin that exposes the AI Gateway admin UI.
 *
 * Host apps opt in by registering it on their panel:
 *
 *     use Andre\AiGateway\Filament\AiGatewayPlugin;
 *
 *     $panel->plugin(AiGatewayPlugin::make());
 *
 * It contributes the integration registry resource plus two settings pages
 * (general runtime toggles + Sanctum API token management).
 */
class AiGatewayPlugin implements Plugin
{
    public function getId(): string
    {
        return 'ai-openrouter-gateway';
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                AiIntegrationResource::class,
            ])
            ->pages([
                GeneralSettings::class,
                ApiTokens::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        // Nothing to boot — registration is enough.
    }
}
