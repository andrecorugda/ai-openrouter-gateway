# Changelog

All notable changes to `ai-openrouter-gateway` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
