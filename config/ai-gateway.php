<?php

declare(strict_types=1);
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Models\AiIntegrationVersion;
use Andre\AiGateway\Models\AiInvocation;

return [

    /*
    |--------------------------------------------------------------------------
    | OpenRouter connection
    |--------------------------------------------------------------------------
    |
    | This package is intentionally tied to OpenRouter — one key reaches every
    | model (Anthropic, OpenAI, Google, DeepSeek, ...). Only the API key is
    | required; everything else has a sane default.
    |
    */

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY', env('OPENROUTER_INTEGRATION_KEY')),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 60),
        // HTTP-Referer / X-Title headers OpenRouter shows on its activity page.
        // Default to the app identity so no extra env vars are needed.
        'referer' => env('OPENROUTER_REFERER', env('APP_URL')),
        'title' => env('OPENROUTER_TITLE', env('APP_NAME', 'Laravel')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default model
    |--------------------------------------------------------------------------
    |
    | The model pre-filled when creating a new integration. Any OpenRouter
    | model slug works.
    |
    */

    'default_model' => env('AI_GATEWAY_DEFAULT_MODEL', 'anthropic/claude-sonnet-4'),

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | The package owns three tables. Point them at any connection and rename
    | them per host. `null` connection = the app's default connection.
    |
    */

    'database' => [
        'connection' => env('AI_GATEWAY_DB_CONNECTION', null),
        'tables' => [
            'integrations' => 'ai_integrations',
            'integration_versions' => 'ai_integration_versions',
            'invocations' => 'ai_invocations',
            'settings' => 'ai_gateway_settings',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Swap any model for an app subclass without touching the package.
    |
    */

    'models' => [
        'integration' => AiIntegration::class,
        'integration_version' => AiIntegrationVersion::class,
        'invocation' => AiInvocation::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Integration resolver cache
    |--------------------------------------------------------------------------
    */

    'cache' => [
        'store' => env('AI_GATEWAY_CACHE_STORE', null), // null = default store
        'ttl_seconds' => (int) env('AI_GATEWAY_CACHE_TTL', 60),
        'prefix' => 'ai-gateway.integration.',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    |
    | The public invocation endpoint: POST {prefix}/{integration}/chat,
    | authenticated with a Sanctum personal access token. Toggle the whole
    | layer off, or flip it at runtime from the admin "General settings" page
    | (the runtime setting wins over this default).
    |
    */

    'api' => [
        'enabled' => (bool) env('AI_GATEWAY_API_ENABLED', true),
        'prefix' => env('AI_GATEWAY_API_PREFIX', 'api/ai'),
        'middleware' => ['api'],
        // Middleware applied on top of `middleware` for authenticated routes.
        'auth_middleware' => ['auth:sanctum'],
        // Sanctum token ability required to call the invoke endpoint. Tokens
        // minted from the admin UI are granted this ability.
        'token_ability' => 'ai-gateway:invoke',
        // Only integrations with these visibilities are reachable over HTTP.
        'public_visibilities' => ['public', 'both'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Per-integration request ceiling, counted per caller per minute via the
    | cache store. `default_per_minute` applies when an integration leaves its
    | own limit blank. null = unlimited.
    |
    */

    'rate_limit' => [
        'enabled' => (bool) env('AI_GATEWAY_RATE_LIMIT_ENABLED', true),
        'default_per_minute' => env('AI_GATEWAY_RATE_LIMIT_PER_MINUTE') !== null
            ? (int) env('AI_GATEWAY_RATE_LIMIT_PER_MINUTE')
            : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost limiting
    |--------------------------------------------------------------------------
    |
    | Daily USD ceiling enforced before each call by summing cost_usd from
    | ai_invocations over a rolling 24h window. `default_daily_usd` applies
    | when an integration leaves its own cap blank. null = uncapped.
    |
    */

    'cost_limit' => [
        'enabled' => (bool) env('AI_GATEWAY_COST_LIMIT_ENABLED', true),
        'default_daily_usd' => env('AI_GATEWAY_COST_LIMIT_DAILY_USD') !== null
            ? (float) env('AI_GATEWAY_COST_LIMIT_DAILY_USD')
            : null,
        'window_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI prompt builder
    |--------------------------------------------------------------------------
    |
    | An in-UI assistant that drafts prompt templates and variable schemas for
    | you. It runs through this same gateway/OpenRouter key, so it lights up
    | automatically once a key is configured. Model is configurable here and
    | overridable at runtime from the admin "General settings" page; it
    | defaults to a fast, cheap Haiku.
    |
    */

    'prompt_builder' => [
        'enabled' => (bool) env('AI_GATEWAY_PROMPT_BUILDER_ENABLED', true),
        'model' => env('AI_GATEWAY_PROMPT_BUILDER_MODEL', 'anthropic/claude-haiku-4.5'),
        'max_tokens' => (int) env('AI_GATEWAY_PROMPT_BUILDER_MAX_TOKENS', 2048),
        'temperature' => 0.4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament admin UI
    |--------------------------------------------------------------------------
    |
    | Register AiGatewayPlugin on your panel to expose the UI. These knobs
    | control where it appears and who may reach it.
    |
    */

    'filament' => [
        'navigation_group' => 'AI & Automation',
        'navigation_sort' => 50,
        'cluster' => null,
        // Gate for the whole UI. A closure/invokable or a Gate ability name.
        // null = visible to anyone who can access the panel.
        'authorize' => env('AI_GATEWAY_FILAMENT_GATE', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invocation defaults
    |--------------------------------------------------------------------------
    */

    'invocations' => [
        // Server-enforced ceiling a caller's max_tokens can never exceed.
        'max_tokens_ceiling' => (int) env('AI_GATEWAY_MAX_TOKENS_CEILING', 8192),
    ],

];
