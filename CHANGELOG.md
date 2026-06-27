# Changelog

All notable changes to `ai-openrouter-gateway` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
