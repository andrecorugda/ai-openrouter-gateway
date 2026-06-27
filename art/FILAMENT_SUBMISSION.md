# Filament Plugins directory — submission sheet

Submit at **https://filamentphp.com/author** (request author access with GitHub, then “Submit plugin”).
Everything below is paste-ready.

---

**Name:** AI OpenRouter Gateway

**Repository:** https://github.com/andrecorugda/ai-openrouter-gateway

**Packagist:** andrecorugda/ai-openrouter-gateway

**Pricing:** Free

**Compatible Filament versions:** 3.x

**Cover image:** `art/cover.jpg` (2560×1440, 16:9, JPEG, light theme)
**Thumbnail:** `art/thumbnail.jpg` (1920×1080, 16:9, JPEG, light theme)

**Dark mode:** Yes (uses Filament's native theming)
**Multilingual:** No (single-language UI for now)

**Suggested categories / tags:** Artificial Intelligence, Developer Tools, API, Integrations

---

**Tagline (one line):**
Manage every AI feature as a versioned, runtime-tunable integration — one OpenRouter key for every model.

---

**Description (markdown):**

A self-hostable, OpenRouter-backed AI gateway for Laravel + Filament. Define each AI use case as a **named integration** — a versioned prompt, a model picked from the live OpenRouter catalog, generation params, and guardrails — and tune it at runtime from the admin panel. No more hardcoded prompts or redeploys to change a model.

**Filament admin UI**
- Searchable **model picker** from the live OpenRouter catalog, with per-model generation params and model-aware prompt caching
- **Interactive prompt editor** with click-to-insert variables + an AI-assisted prompt builder
- **Versions** — every save is a new version you can load back into the form (rollback)
- **Invocations** telemetry browser — tokens, cost, latency, status, with cost summaries
- **API tokens** (Sanctum) with optional expiry and one-click copy
- **API docs** — an interactive OpenAPI/Scalar reference embedded in the panel
- **General settings** — toggle the HTTP API and the prompt builder at runtime

**Engine**
- Call it from PHP (`AiGateway::invoke('slug', [...])`) or over an authenticated HTTP API
- Multi-turn **conversation threads** (`/start` + `/converse`)
- Per-integration **rate limits** and **daily cost caps**; full per-call telemetry
- One key reaches every model — Claude, GPT, Gemini, DeepSeek, and more

Your prompts, customer data, and OpenRouter key never leave your app — no third-party SaaS in the trust boundary.

```bash
composer require andrecorugda/ai-openrouter-gateway
```
