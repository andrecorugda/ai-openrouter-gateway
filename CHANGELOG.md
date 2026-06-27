# Changelog

All notable changes to `ai-openrouter-gateway` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.2] - 2026-06-27

### Added
- **Dark-mode API docs.** The Scalar reference (standalone `{prefix}/docs` page and the panel-embedded "API docs" iframe) now renders in dark mode, matching Filament's dark theme. Configurable via `config('ai-gateway.api.docs.dark_mode')` / `AI_GATEWAY_API_DOCS_DARK` (default `true`; set `false` for light).

### Docs
- Refreshed the API-docs screenshots to the dark theme.

## [1.3.1] - 2026-06-27

### Added
- **API docs as a Filament page** — the interactive Scalar reference is now embedded in the admin panel (under AI & Automation → API docs) via an iframe, so admins can browse/test the API without leaving Filament. Hidden when the API or its docs are disabled.

## [1.3.0] - 2026-06-27

### Added
- **Live API docs** — a dynamically generated OpenAPI 3 spec (`GET {prefix}/openapi.json`) and an interactive Scalar docs page (`GET {prefix}/docs`) built from your integrations. Each API-visible integration becomes real endpoints with a request body shaped from its `prompt_args` (types + required); conversational integrations also expose `/start` + `/converse`; model + caching mode are surfaced in the descriptions. Toggle/gate via `ai-gateway.api.docs`.

## [1.2.2] - 2026-06-27

### Fixed
- The token "Copy to clipboard" button threw an Alpine `SyntaxError: Unexpected token '&'` — the inline handler contained `=>` and quotes that get HTML-entity-escaped in the attribute. Rewrote it to stash the token in a `data-*` attribute and use an expression with no HTML-special characters (`navigator.clipboard?.writeText($el.dataset.token)`).

## [1.2.1] - 2026-06-27

### Added
- The one-time token notification now has a **Copy to clipboard** button (one click, with a brief "Copied!" confirmation). Requires a secure context (https or localhost).

## [1.2.0] - 2026-06-27

### Added
- **API token expiry** — the API Tokens page can mint tokens that expire in 7/30/90 days or 1 year (or never). Uses Sanctum's native per-token `expires_at`, so the auth guard rejects expired tokens automatically. The table shows an "Expires" column flagged when past; sweep them with `sanctum:prune-expired`.

## [1.1.4] - 2026-06-27

### Fixed
- On Create, the generation params now seed from the pre-selected default model immediately, instead of staying empty until the model was changed and changed back (the per-model seeder only fired on a change event).

## [1.1.3] - 2026-06-27

### Fixed
- Invocation detail now links the generation id to the correct OpenRouter URL `https://openrouter.ai/logs?transaction={id}` (still copyable).

## [1.1.2] - 2026-06-27

### Fixed
- Invocation detail linked to `openrouter.ai/activity/{id}`, which isn't a valid OpenRouter route (it 404s as a model slug). The `openrouter_generation_id` is now shown as a **copyable** value with a hint to look it up in the OpenRouter dashboard or via `GET /api/v1/generation?id=…`.

## [1.1.1] - 2026-06-27

### Fixed
- Invocations browser showed `$0.00` for sub-cent calls because `money('usd')` rounds to 2 decimals. Cost column, Σ summary, and detail now show up to 6 decimal places (costs were always stored correctly).

## [1.1.0] - 2026-06-27

### Added
- **Conversation threads** — opt-in multi-turn memory for integrations flagged `is_conversational`:
  - `AiGateway::converse($slug, $conversationId, $message, $args, $opts)` and `startConversation()`; stateless `invoke/chat/complete` unchanged.
  - HTTP: `POST {prefix}/{integration}/start` (mints a thread) + `POST {prefix}/{integration}/converse` (continues it).
  - `ai_conversations` + `ai_conversation_messages` tables (each turn links to its `ai_invocations` row); `is_conversational` + `conversation_ttl_minutes` on integrations.
  - `ConversationStore` service, per-caller ownership scoping, TTL expiry, and the `ai-gateway:prune-conversations` command.
  - Filament: Conversational toggle + TTL on the integration form.

## [1.0.0] - 2026-06-27

First stable release. `composer require andrecorugda/ai-openrouter-gateway` now resolves to `^1.0`, so `composer update` tracks the latest stable 1.x.

Consolidates 0.1–0.4: OpenRouter-backed gateway (`invoke`/`chat`/`complete`), versioned prompts, per-integration rate + daily-cost limits, Sanctum HTTP API, and the Filament admin UI — live model catalog picker, per-model generation params, model-aware prompt caching, interactive prompt editor, variables modal, versions load-into-form, the AI prompt builder, and the read-only invocations telemetry browser.

## [0.4.0] - 2026-06-27

### Added
- **Invocations browser** — read-only Filament resource over `ai_invocations`: columns for integration, status, model, caller, tokens, cost, latency; filters by status / caller / integration / date range; cost + token **Σ summaries**; a per-row **detail modal** (usage, cost, error, OpenRouter generation link); 30s live poll.

## [0.3.1] - 2026-06-27

### Changed
- Integration form layout: **Identity + Models** side by side; **System prompt** and **Generation parameters** full width; **Server tools + Limits + Visibility** in three columns — less vertical scrolling.

## [0.3.0] - 2026-06-27

### Added
- **Versions on the Edit page**: pick a past version to load its editable surface into the form (optionally activate it); Save mints it as the new active version (rollback-by-clone).
- **Variables modal**: declare `prompt_args` in a "Manage variables" modal; the prompt composer's side panel lists them for click-to-insert (declaration no longer clutters the layout).

### Changed
- Generation-params editor now seeds **all** of the selected model's tunable params (not only those with a documented default); structural params (tools/response_format/…) are excluded.
- Gateway drops empty-string params before sending, so seeded-but-unfilled params aren't forwarded.

### Fixed
- Versions list action crashed with `suffixAction(null)` for the active version; now uses `suffixActions()` with the null filtered out.

## [0.2.0] - 2026-06-27

### Added
- **OpenRouter model catalog** (`OpenRouterModelCatalog`): live `GET /models`, cached (`models_catalog.ttl_seconds`, 1h), exposing per-model `supported_parameters`, suggested defaults, and prompt-caching mode.
- **Interactive prompt editor** (`PromptComposer` field): textarea + a toggleable variables side-panel that inserts `{{name}}` at the cursor on click.

### Changed
- Model selection is now a **searchable Select from the live catalog** (primary + fallbacks) instead of free text.
- **Generation params auto-seed** from the selected model's supported parameters (existing values preserved).
- **Prompt-caching toggle** shows only for cache-eligible (explicit) models; an "auto-cached" note shows for providers that cache automatically; hidden otherwise.
- **General Settings** `prompt_builder_model` is now a catalog-backed Select.
- Removed the editable **provider** field — always `openrouter`.

## [0.1.4] - 2026-06-27

### Fixed
- **Filament integration form**: the `slug` field called non-existent `TextInput::alphaDash()`/`lowercase()` (500 on create/edit). Replaced with a `regex` rule; aligned `maxLength` to the `varchar(64)` column.
- **Filament Edit page**: header reused a `Tables\Actions\Action` where a `Filament\Actions\Action` is required. Added a proper page-header Test action and extracted the shared `runTest()` helper used by both the table and the header.

## [0.1.3] - 2026-06-27

### Fixed
- **Filament General Settings** page: replace the Resource-only `getCachedFormActions()` call in the view with a plain submit button (was 500ing on a plain Page).
- **Filament API Tokens** page: `sanctumReady()` now returns `hasTable()`'s result instead of always `true`, so it degrades gracefully (and the create action already guards a non-token `User`).

### Docs
- README: document the Sanctum prerequisite (install/migrate + `HasApiTokens` on `User`) for the HTTP API and API Tokens page.

## [0.1.2] - 2026-06-27

### Fixed
- Correct the `vendor:publish` tags in the README (`ai-openrouter-gateway-migrations` / `-config`).

## [0.1.1] - 2026-06-27

### Changed
- Widen Laravel support to include **Laravel 13** (`illuminate/* ^11|^12|^13`). A fresh Laravel app (now v13) can `composer require` the package again.

## [0.1.0] - 2026-06-27

### Added
- Initial release.
- `AiGateway` service: `invoke()`, `chat()`, `complete()` over OpenRouter with one key.
- Integration registry with versioned prompts (`ai_integrations` + `ai_integration_versions`).
- Per-call telemetry (`ai_invocations`): tokens, cost, latency, model, status, generation id.
- Per-integration **rate limiting** (requests/minute/caller) and **daily cost limiting** (USD).
- OpenRouter **server tools** (`web_search` / `web_fetch`) per version.
- Sanctum-authenticated HTTP API: `POST {prefix}/{integration}/chat`, toggleable at runtime.
- API token management from the admin UI (mint / revoke tokens with the invoke ability).
- Filament admin UI: integration CRUD, version history, test panel, invocation log, general settings.
- **AI prompt builder**: an in-UI assistant that drafts prompt templates + variable schemas,
  defaulting to a fast Haiku model and configurable from general settings.
- Fully configurable: connection, table names, route prefix/middleware, cache store, limits.
