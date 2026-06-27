<?php

declare(strict_types=1);

namespace Andre\AiGateway;

use Andre\AiGateway\Services\AiGateway;
use Andre\AiGateway\Services\AiIntegrationResolver;
use Andre\AiGateway\Services\AiIntegrationService;
use Andre\AiGateway\Services\OpenRouterClient;
use Andre\AiGateway\Services\PromptBuilderService;
use Andre\AiGateway\Services\PromptRenderer;
use Andre\AiGateway\Services\UsageGuard;
use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AiGatewayServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ai-openrouter-gateway')
            ->hasConfigFile('ai-gateway')
            // Register the bundled Filament page views under the `ai-gateway::`
            // namespace (used by GeneralSettings & ApiTokens pages).
            ->hasViews('ai-gateway')
            ->hasMigrations([
                'create_ai_integrations_table',
                'create_ai_integration_versions_table',
                'create_ai_invocations_table',
                'create_ai_gateway_settings_table',
            ]);
    }

    public function packageRegistered(): void
    {
        // Stateless helpers — singletons are fine.
        $this->app->singleton(OpenRouterClient::class);
        $this->app->singleton(PromptRenderer::class);
        $this->app->singleton(AiIntegrationResolver::class);
        $this->app->singleton(UsageGuard::class);
        $this->app->singleton(AiIntegrationService::class);
        $this->app->singleton(PromptBuilderService::class);
        $this->app->singleton(AiGateway::class);
    }

    public function packageBooted(): void
    {
        $this->registerApiRoutes();
    }

    private function registerApiRoutes(): void
    {
        if (! (bool) config('ai-gateway.api.enabled', true)) {
            return;
        }

        Route::group([], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/api.php'));
    }
}
