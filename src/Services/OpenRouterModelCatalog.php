<?php

declare(strict_types=1);

namespace Andre\AiGateway\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches and caches the OpenRouter model catalog (GET /models) so the admin UI
 * can offer a real, searchable model list and derive per-model generation
 * params + prompt-caching eligibility — mirroring gvnext's
 * AiIntegrationService::modelsCatalog().
 *
 * Degrades gracefully: on an upstream failure it returns the last good cached
 * list, or an empty list — never throws into the UI.
 */
class OpenRouterModelCatalog
{
    private const CACHE_KEY = 'ai-gateway.openrouter.models';

    /**
     * Common generation-param defaults used when a model advertises a param in
     * `supported_parameters` but offers no `default_parameters` value for it.
     * Mirrors gvnext's DOCUMENTED_DEFAULTS.
     *
     * @var array<string,int|float>
     */
    private const DOCUMENTED_DEFAULTS = [
        'temperature' => 0.7,
        'top_p' => 1.0,
        'max_tokens' => 1024,
        'frequency_penalty' => 0.0,
        'presence_penalty' => 0.0,
        'repetition_penalty' => 1.0,
        'top_k' => 0,
        'min_p' => 0.0,
        'top_a' => 0.0,
    ];

    /**
     * Full normalized catalog: list of
     * { id, name, description, context_length, pricing, supported_parameters,
     *   default_parameters, architecture }.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(bool $refresh = false): array
    {
        if ($refresh) {
            $this->cache()->forget(self::CACHE_KEY);
        }

        $ttl = (int) config('ai-gateway.models_catalog.ttl_seconds', 3600);

        $cached = $this->cache()->get(self::CACHE_KEY);
        if (! $refresh && is_array($cached)) {
            return $cached;
        }

        try {
            $models = $this->fetch();
        } catch (Throwable $e) {
            Log::warning('AiGateway: OpenRouter model catalog fetch failed', ['error' => $e->getMessage()]);

            // Fall back to stale cache if present, else empty.
            return is_array($cached) ? $cached : [];
        }

        $this->cache()->put(self::CACHE_KEY, $models, $ttl);

        return $models;
    }

    /**
     * Select options: [id => "Name (id)"], vendor-sorted.
     *
     * @return array<string,string>
     */
    public function options(): array
    {
        $out = [];
        foreach ($this->all() as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $name = (string) ($m['name'] ?? $id);
            $out[$id] = $name === $id ? $id : "{$name}  ({$id})";
        }
        ksort($out);

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $m) {
            if (($m['id'] ?? null) === $id) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Param names this model accepts (drives the generation-params editor).
     *
     * @return array<int,string>
     */
    public function supportedParameters(string $id): array
    {
        $m = $this->find($id);
        $params = $m['supported_parameters'] ?? [];

        return is_array($params) ? array_values(array_filter($params, 'is_string')) : [];
    }

    /**
     * Structural / non-scalar params that don't belong in a simple key/value
     * generation-params editor (objects/arrays configured elsewhere).
     *
     * @var array<int,string>
     */
    private const NON_SCALAR_PARAMS = [
        'tools', 'tool_choice', 'response_format', 'structured_outputs',
        'stop', 'logit_bias', 'reasoning', 'include_reasoning', 'logprobs',
        'top_logprobs', 'prediction', 'modalities', 'web_search_options',
    ];

    /**
     * The full set of *tunable* params to surface for this model, so the editor
     * shows everything the model accepts (gvnext's "show all available params").
     * Each gets the model's own default, else a documented default, else an
     * empty string (so the key is visible and editable). Structural params
     * (tools / response_format / …) are excluded — not simple key/value inputs.
     *
     * @return array<string,int|float|string|bool>
     */
    public function defaultParametersFor(string $id): array
    {
        $m = $this->find($id);
        $modelDefaults = is_array($m['default_parameters'] ?? null) ? $m['default_parameters'] : [];

        $out = [];
        foreach ($this->supportedParameters($id) as $param) {
            if (in_array($param, self::NON_SCALAR_PARAMS, true)) {
                continue;
            }
            $v = $modelDefaults[$param] ?? null;
            if ($v !== null && is_scalar($v)) {
                $out[$param] = $v;
            } elseif (array_key_exists($param, self::DOCUMENTED_DEFAULTS)) {
                $out[$param] = self::DOCUMENTED_DEFAULTS[$param];
            } else {
                $out[$param] = '';
            }
        }

        return $out;
    }

    /**
     * Prompt-caching mode for a model slug. Ported from gvnext's
     * detectCachingMode():
     *   - explicit    → admin opts in via cache_control (Anthropic / Qwen / older Gemini)
     *   - automatic   → provider caches on its own (OpenAI / DeepSeek / Grok / Gemini 2.5+ / Groq / Moonshot)
     *   - unsupported → no caching
     */
    public function cachingMode(?string $id): string
    {
        $m = strtolower(trim((string) $id));
        if ($m === '') {
            return 'unsupported';
        }
        if (str_starts_with($m, 'anthropic/') || str_starts_with($m, 'qwen/')) {
            return 'explicit';
        }
        if (str_starts_with($m, 'google/gemini-2.5') || str_starts_with($m, 'google/gemini-3')) {
            return 'automatic';
        }
        if (str_starts_with($m, 'google/gemini-')) {
            return 'explicit';
        }
        foreach (['openai/', 'deepseek/', 'x-ai/', 'xai/', 'groq/', 'moonshotai/'] as $auto) {
            if (str_starts_with($m, $auto)) {
                return 'automatic';
            }
        }

        return 'unsupported';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetch(): array
    {
        $apiKey = (string) config('ai-gateway.openrouter.api_key', '');
        if ($apiKey === '') {
            return [];
        }

        $baseUrl = rtrim((string) config('ai-gateway.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
        $headers = ['Authorization' => 'Bearer '.$apiKey, 'Accept' => 'application/json'];
        if ($referer = config('ai-gateway.openrouter.referer')) {
            $headers['HTTP-Referer'] = $referer;
        }
        if ($title = config('ai-gateway.openrouter.title')) {
            $headers['X-Title'] = $title;
        }

        $json = Http::withHeaders($headers)
            ->timeout((int) config('ai-gateway.openrouter.timeout', 60))
            ->get($baseUrl.'/models')
            ->throw()
            ->json();

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        $models = [];
        foreach ($data as $m) {
            if (! is_array($m) || ! isset($m['id'])) {
                continue;
            }
            $models[] = [
                'id' => (string) $m['id'],
                'name' => (string) ($m['name'] ?? $m['id']),
                'description' => isset($m['description']) ? (string) $m['description'] : null,
                'context_length' => isset($m['context_length']) ? (int) $m['context_length'] : null,
                'pricing' => is_array($m['pricing'] ?? null) ? $m['pricing'] : [],
                'supported_parameters' => is_array($m['supported_parameters'] ?? null) ? array_values($m['supported_parameters']) : [],
                'default_parameters' => is_array($m['default_parameters'] ?? null) ? $m['default_parameters'] : [],
                'architecture' => is_array($m['architecture'] ?? null) ? $m['architecture'] : [],
            ];
        }

        return $models;
    }

    private function cache(): Repository
    {
        $store = config('ai-gateway.cache.store');

        return $store !== null ? Cache::store($store) : Cache::store();
    }
}
