<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Pages;

use Andre\AiGateway\Support\Settings;
use Filament\Pages\Page;

/**
 * Embeds the interactive OpenAPI (Scalar) docs inside the panel via an iframe,
 * so admins can browse and try the API without leaving Filament. The iframe
 * isolates Scalar's full-page assets from the panel's styles.
 *
 * Hidden when the HTTP API or its docs are disabled.
 */
class ApiDocs extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected string $view = 'ai-gateway::filament.pages.api-docs';

    protected static ?string $title = 'API docs';

    public static function getNavigationGroup(): ?string
    {
        return config('ai-gateway.filament.navigation_group', 'AI Gateway');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-gateway.filament.navigation_sort', 50) + 4;
    }

    public static function canAccess(): bool
    {
        return Settings::bool('api_enabled') && (bool) config('ai-gateway.api.docs.enabled', true);
    }

    /** Built from config so it doesn't depend on the named route being registered. */
    public function getDocsUrl(): string
    {
        return url(trim((string) config('ai-gateway.api.prefix', 'api/ai'), '/').'/docs');
    }
}
