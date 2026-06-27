# AI OpenRouter Gateway for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/andrecorugda/ai-openrouter-gateway.svg?style=flat-square)](https://packagist.org/packages/andrecorugda/ai-openrouter-gateway)
[![Tests](https://img.shields.io/github/actions/workflow/status/andrecorugda/ai-openrouter-gateway/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/andrecorugda/ai-openrouter-gateway/actions)
[![License](https://img.shields.io/packagist/l/andrecorugda/ai-openrouter-gateway.svg?style=flat-square)](LICENSE)

A self-hostable, **OpenRouter-backed AI gateway** for Laravel. One API key reaches every model — Anthropic Claude, OpenAI GPT, Google Gemini, DeepSeek, and more — behind **one service, one audit log, one cost view**.

Each AI use case is a **named integration** with a versioned prompt template, a declared variable schema, generation params, and guardrails. Call it from PHP (`AiGateway::invoke('my_use_case', [...])`) or over an authenticated HTTP API. A bundled **Filament admin UI** lets non-developers create and tune integrations — complete with an **AI-assisted prompt builder**.

> Your prompts, your customer data, and your OpenRouter key never leave your app. No third-party SaaS in the trust boundary.

---

## Why this exists

AI features usually start with a prompt hardcoded in a controller and a single model id baked into the code. Then reality hits: the prompt needs tuning weekly, you want to try a cheaper model, and three different apps need the same capability. Every change becomes a code edit, a pull request, a review, and a deploy.

This package moves that whole surface out of code and into a managed **integration** you tune at runtime:

- **Stop hardcoding prompts.** Prompts, variables, model choice, and generation params live in the database and are edited in the admin UI — not in your source tree.
- **Fine-tune on the fly — no PR, no redeploy.** Tweak a prompt, bump `temperature`, or swap the model and save. The next call uses it immediately. Every save mints a new **version**, so you can roll back by loading an old one into the form.
- **Test across models in seconds.** Pick any model from the live OpenRouter catalog, hit **Test**, and compare output, tokens, latency, and cost — without touching code. Switch the production model when you find a better/cheaper one.
- **One integration, many callers.** Define a use case once and invoke it from anywhere — any PHP service or job (`AiGateway::invoke('lead_summary', [...])`) **and** any external app, language, or codebase over **HTTPS** (`POST /api/ai/lead_summary/chat` with a scoped token). One source of truth, reused across platforms.
- **Governance built in.** Every call is logged with tokens, cost, latency, and status; per-integration **rate limits** and **daily cost caps** stop runaway spend before it happens.

In short: prompts and models become **configuration**, not code — owned by the people who tune them, observable, and callable from everything.

---

## How it works

### Who uses it (use cases)

```mermaid
flowchart LR
    DEV(["Developer / PHP service"])
    EXT(["External app / other codebase"])
    ADMIN(["Admin / non-developer"])

    subgraph SYS["AI OpenRouter Gateway"]
        UC1("Invoke an integration")
        UC2("Hold a multi-turn conversation")
        UC3("Create &amp; version integrations")
        UC4("Draft a prompt with AI")
        UC5("Test the same use case across models")
        UC6("Review invocations, cost &amp; tokens")
        UC7("Mint / revoke API tokens")
        UC8("Toggle the API &amp; settings")
    end

    DEV --> UC1
    DEV --> UC2
    EXT -->|"HTTPS + token"| UC1
    EXT -->|"HTTPS + token"| UC2
    ADMIN --> UC3
    ADMIN --> UC4
    ADMIN --> UC5
    ADMIN --> UC6
    ADMIN --> UC7
    ADMIN --> UC8
```

### Architecture

```mermaid
flowchart TB
    PHP["PHP code &amp; jobs<br/>AiGateway facade"]
    HTTP["External apps<br/>HTTPS + Sanctum token"]
    UI["Admins<br/>Filament panel"]

    subgraph PKG["AI OpenRouter Gateway (package)"]
        direction TB
        GW["AiGateway<br/>invoke · chat · converse"]
        RES["AiIntegrationResolver<br/>(cached lookup)"]
        PR["PromptRenderer<br/>variable substitution"]
        UG["UsageGuard<br/>rate + cost limits"]
        CS["ConversationStore<br/>threads"]
        CAT["OpenRouterModelCatalog<br/>live model list"]
        ORC["OpenRouterClient<br/>HTTP transport"]
    end

    DB[("Database<br/>integrations · versions<br/>invocations · conversations · settings")]
    OR["OpenRouter API"]
    MODELS["Anthropic · OpenAI · Google · DeepSeek · …"]

    PHP --> GW
    HTTP -->|"/chat · /start · /converse"| GW
    UI -->|"manage rows"| DB
    UI -->|"Test · Draft with AI"| GW
    UI --> CAT

    GW --> RES
    GW --> PR
    GW --> UG
    GW --> CS
    GW --> ORC
    RES --> DB
    UG --> DB
    CS --> DB
    GW -->|"writes telemetry"| DB
    CAT --> OR
    ORC --> OR
    OR --> MODELS
```

**Request flow (one call):** resolve the integration's active version (cached) → render the prompt template with the caller's args → enforce rate + daily-cost limits → compose the OpenRouter payload (model[s], params, server tools, optional cache markers) → call OpenRouter → write an `ai_invocations` telemetry row (cost / tokens / latency / status) → return a typed `AiResult`. Conversational calls additionally load and persist thread turns via `ConversationStore`.

---

## Features

- 🔌 **One key, every model** — OpenRouter under the hood; switch models per-integration with no code change.
- 🧩 **Named integrations** — register a use case once, invoke it by slug everywhere.
- 🗂️ **Versioned prompts** — every edit mints a new version; activate/roll back without losing history.
- 🧮 **Telemetry built in** — tokens, cost, latency, model, and status for every call in `ai_invocations`.
- 🚦 **Rate limiting** — per-integration, per-caller, per-minute.
- 💰 **Cost limiting** — per-integration daily USD budget, enforced before each call.
- 🌐 **HTTP API** — `POST /api/ai/{integration}/chat`, Sanctum-authenticated, toggleable at runtime.
- 💬 **Conversation threads** — opt-in multi-turn memory with `/start` + `/converse`, per-caller ownership, TTL expiry, and a prune command.
- 🔑 **API token management** — mint and revoke scoped tokens from the admin UI.
- 🔎 **OpenRouter server tools** — per-version `web_search` / `web_fetch`.
- ✨ **AI prompt builder** — describe what you want; a fast Haiku drafts the template + variables.
- 🗂️ **Live model catalog** — searchable model picker from OpenRouter's `/models`, with per-model generation params and caching eligibility.
- 📊 **Invocations browser** — read-only telemetry with status/caller/date filters, cost + token Σ summaries, and per-call detail.
- 🎛️ **Filament admin UI** — integration CRUD, versions (load-into-form), a live test panel, an interactive prompt editor, settings.
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

- **AI Integrations** — create/edit integrations with a **searchable model picker from the live OpenRouter catalog**, **generation params that auto-populate per model**, a **model-aware prompt-caching** control, an **interactive prompt editor** (click a declared variable to insert `{{name}}`), a **Versions** action that loads any past version back into the form, and a **Test** panel that runs it live and shows tokens/cost/latency.
- **Draft with AI** — describe the use case in plain language; the prompt builder fills the template and variable schema for you.
- **Invocations** — a read-only telemetry browser: filter by status / caller / integration / date, with cost + token Σ summaries and a per-call detail modal (usage, error, OpenRouter generation link).
- **General settings** — toggle the HTTP API, toggle the prompt builder, and pick the helper model (also a catalog-backed Select).
- **API Tokens** — mint and revoke scoped invocation tokens.

### Screenshots

| | |
|---|---|
| **Integration form** — catalog model picker, per-model params, caching, prompt editor | ![Create integration](screenshots/integration-create.png) |
| **Integrations list** | ![Integrations](screenshots/integrations-list.png) |
| **Invocations** — telemetry with Σ summaries | ![Invocations](screenshots/invocations.png) |
| **Versions** — load a past version into the form | ![Versions](screenshots/modal-versions.png) |
| **General settings** | ![General settings](screenshots/general-settings.png) |
| **API tokens** | ![API tokens](screenshots/api-tokens.png) |

## Conversations (multi-turn threads)

Flag an integration **conversational** (UI toggle, or `is_conversational` + `conversation_ttl_minutes`) to get server-side memory: the gateway persists each turn, so clients send only the next message — no replaying history.

From PHP:

```php
use Andre\AiGateway\Facades\AiGateway;

$first  = AiGateway::converse('support', null, 'My order is late');      // null → new thread
$id     = $first->conversation_id;                                       // keep this
$second = AiGateway::converse('support', $id, 'Order #4471');            // continues with full history
```

Over HTTP (two calls, à la a chatbot `/start` then `/chat`):

```bash
# 1) open a thread
curl -X POST https://your-app.test/api/ai/support/start \
  -H "Authorization: Bearer <token>"
# → { "data": { "conversation_id": "0779…", "expires_at": "…" } }

# 2) send turns
curl -X POST https://your-app.test/api/ai/support/converse \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"conversation_id": "0779…", "message": "Order #4471"}'
```

Threads are owned by their caller (a guessed id returns 404), expire after the TTL, and link each turn to its telemetry row. Prune expired threads on a schedule:

```php
// routes/console.php
Schedule::command('ai-gateway:prune-conversations')->daily();
```

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

Each row keeps OpenRouter's `openrouter_generation_id` — look it up in your OpenRouter dashboard (`openrouter.ai/activity`) or via `GET /api/v1/generation?id=…` for full provider-side cost forensics.

## Testing

```bash
composer install
vendor/bin/pest
```

## Security

The gateway never sends data to anyone but OpenRouter. Rotate your key by updating `OPENROUTER_API_KEY` and redeploying. If you discover a vulnerability, please email andre.corugda@ins-global.com.

## Credits

Built by [Andre Corugda](https://github.com/andrecorugda).

## License

The MIT License (MIT). See [LICENSE](LICENSE).
