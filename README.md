# AI OpenRouter Gateway for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/andrecorugda/ai-openrouter-gateway.svg?style=flat-square)](https://packagist.org/packages/andrecorugda/ai-openrouter-gateway)
[![Tests](https://img.shields.io/github/actions/workflow/status/andrecorugda/ai-openrouter-gateway/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/andrecorugda/ai-openrouter-gateway/actions)
[![License](https://img.shields.io/packagist/l/andrecorugda/ai-openrouter-gateway.svg?style=flat-square)](LICENSE)

A self-hostable, **OpenRouter-backed AI gateway** for Laravel. One API key reaches every model — Anthropic Claude, OpenAI GPT, Google Gemini, DeepSeek, and more — behind **one service, one audit log, one cost view**.

Each AI use case is a **named integration** with a versioned prompt template, a declared variable schema, generation params, and guardrails. Call it from PHP (`AiGateway::invoke('my_use_case', [...])`) or over an authenticated HTTP API. A bundled **Filament admin UI** lets non-developers create and tune integrations — complete with an **AI-assisted prompt builder**.

> Your prompts, your customer data, and your OpenRouter key never leave your app. No third-party SaaS in the trust boundary.

---

## Features

- 🔌 **One key, every model** — OpenRouter under the hood; switch models per-integration with no code change.
- 🧩 **Named integrations** — register a use case once, invoke it by slug everywhere.
- 🗂️ **Versioned prompts** — every edit mints a new version; activate/roll back without losing history.
- 🧮 **Telemetry built in** — tokens, cost, latency, model, and status for every call in `ai_invocations`.
- 🚦 **Rate limiting** — per-integration, per-caller, per-minute.
- 💰 **Cost limiting** — per-integration daily USD budget, enforced before each call.
- 🌐 **HTTP API** — `POST /api/ai/{integration}/chat`, Sanctum-authenticated, toggleable at runtime.
- 🔑 **API token management** — mint and revoke scoped tokens from the admin UI.
- 🔎 **OpenRouter server tools** — per-version `web_search` / `web_fetch`.
- ✨ **AI prompt builder** — describe what you want; a fast Haiku drafts the template + variables.
- 🎛️ **Filament admin UI** — integration CRUD, version history, a live test panel, invocation log, settings.
- ⚙️ **Fully configurable** — connection, table names, route prefix/middleware, cache store, limits, models.

## Requirements

- PHP 8.2+
- Laravel 11, 12, or 13
- An [OpenRouter](https://openrouter.ai) API key
- Filament 3.2+ *(optional — only for the admin UI; use a Filament release that supports your Laravel version)*

## Installation

```bash
composer require andrecorugda/ai-openrouter-gateway
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="ai-openrouter-gateway-migrations"
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag="ai-openrouter-gateway-config"
```

Add your key to `.env`:

```env
OPENROUTER_API_KEY=sk-or-v1-...
```

That's the only required variable — referer/title default to your `APP_URL` / `APP_NAME`.

## Quickstart

### 1. Create an integration

Either through the Filament UI (recommended) or in code:

```php
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Services\AiIntegrationService;

$integration = AiIntegration::create([
    'slug' => 'expense_extract',
    'name' => 'Expense Extractor',
    'visibility' => 'internal',
]);

app(AiIntegrationService::class)->saveVersion($integration, [
    'system_prompt' => 'Extract the merchant, total, and date from this receipt:\n\n{{receipt_text}}',
    'models' => ['anthropic/claude-sonnet-4', 'openai/gpt-4o'], // primary + fallback
    'default_params' => ['max_tokens' => 512, 'temperature' => 0.1],
    'prompt_args' => [
        ['name' => 'receipt_text', 'type' => 'string', 'required' => true],
    ],
]);
```

### 2. Invoke it from PHP

```php
use Andre\AiGateway\Facades\AiGateway;

$result = AiGateway::invoke('expense_extract', [
    'receipt_text' => $ocrText,
]);

$result->text;        // the assistant's reply
$result->model_used;  // 'anthropic/claude-sonnet-4'
$result->cost_usd;    // 0.0021
$result->usage;       // ['prompt_tokens' => ..., 'completion_tokens' => ...]
```

Multi-turn chat layers messages on top of the templated system prompt:

```php
$result = AiGateway::invoke('support_assistant',
    args: ['kb_version' => 'v3'],
    messages: [
        ['role' => 'user', 'content' => 'How do I reset my password?'],
    ],
);
```

### 3. Or call it over HTTP

The HTTP API and the **API Tokens** admin page are authenticated with
[Laravel Sanctum](https://laravel.com/docs/sanctum). One-time setup in your app:

```bash
composer require laravel/sanctum
php artisan migrate            # creates personal_access_tokens
```

Then add the `HasApiTokens` trait to your `User` model:

```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    // ...
}
```

Now call the endpoint:

```bash
curl -X POST https://your-app.test/api/ai/expense_extract/chat \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"args": {"receipt_text": "..."}, "options": {"max_tokens": 256}}'
```

Mint the token from the admin UI (**API Tokens** page) or in code:

```php
$token = $user->createToken('integration-client', ['ai-gateway:invoke'])->plainTextToken;
```

## The admin UI (Filament)

Register the plugin on your panel:

```php
use Andre\AiGateway\Filament\AiGatewayPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(AiGatewayPlugin::make());
}
```

You get:

- **AI Integrations** — create/edit integrations, manage versions, and a **Test** panel that runs a draft version live and shows tokens/cost/latency.
- **Draft with AI** — describe the use case in plain language; the prompt builder fills the template and variable schema for you.
- **General settings** — toggle the HTTP API, toggle the prompt builder, and pick the helper model.
- **API Tokens** — mint and revoke scoped invocation tokens.

## Rate & cost limiting

Set ceilings per integration (UI → Limits, or the `rate_limit_per_minute` / `max_daily_cost_usd` columns). Blank falls back to the config default; a `null` default means unlimited.

```php
// config/ai-gateway.php
'rate_limit' => [
    'enabled' => true,
    'default_per_minute' => 60,   // null = unlimited
],
'cost_limit' => [
    'enabled' => true,
    'default_daily_usd' => 25.0,  // null = uncapped
    'window_hours' => 24,
],
```

When a caller exceeds a limit the gateway throws `RateLimitExceededException` (HTTP **429**) or `CostLimitExceededException` (HTTP **402**) before any spend occurs.

## Configuration highlights

Everything in `config/ai-gateway.php` is overridable. Common knobs:

| Key | Purpose |
|---|---|
| `openrouter.api_key` | Your OpenRouter key (`OPENROUTER_API_KEY`). |
| `default_model` | Model pre-filled on new integrations. |
| `database.connection` / `database.tables` | Relocate / rename the package's tables. |
| `models.*` | Swap any Eloquent model for an app subclass. |
| `cache.store` / `cache.ttl_seconds` | Integration-resolver cache. |
| `api.enabled` / `api.prefix` / `api.middleware` / `api.token_ability` | HTTP API surface. |
| `prompt_builder.model` | Helper model (defaults to `anthropic/claude-haiku-4.5`). |
| `filament.navigation_group` / `filament.authorize` | Admin UI placement & access gate. |

## Observability

Every call writes one row to `ai_invocations` (success and failure both):

```php
use Andre\AiGateway\Models\AiInvocation;

// Per-integration spend over the last 24h
AiInvocation::where('ai_integration_id', $id)
    ->where('created_at', '>=', now()->subDay())
    ->sum('cost_usd');
```

Each row links to `https://openrouter.ai/activity/{generation_id}` for full provider-side cost forensics.

## Testing

```bash
composer install
vendor/bin/pest
```

## Security

The gateway never sends data to anyone but OpenRouter. Rotate your key by updating `OPENROUTER_API_KEY` and redeploying. If you discover a vulnerability, please email andre.corugda@ins-global.com.

## Credits

Built by [Andre Corugda](https://github.com/andrecorugda). Extracted and generalized from the GlobalView Next AI Gateway.

## License

The MIT License (MIT). See [LICENSE](LICENSE).
