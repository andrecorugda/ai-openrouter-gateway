<?php

declare(strict_types=1);

namespace Andre\AiGateway\Services;

use Andre\AiGateway\Exceptions\InvalidPromptArgsException;
use Andre\AiGateway\Exceptions\OpenRouterRequestException;
use Andre\AiGateway\Models\AiConversation;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Models\AiIntegrationVersion;
use Andre\AiGateway\Models\AiInvocation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single entry point for AI invocations.
 *
 * Flow: resolve the integration (cached) → run usage guards (rate + cost) →
 * compose the OpenRouter payload from the active version → call OpenRouter →
 * write an AiInvocation telemetry row (ok / fallback / error) → return AiResult.
 *
 * Owned payload keys (`messages`, `models`, `provider`, `usage`, `model`,
 * `timeout`) are stripped from both the version's default_params and the
 * caller's $opts before merging so neither can hijack the request shape.
 */
class AiGateway
{
    public function __construct(
        private readonly OpenRouterClient $client,
        private readonly AiIntegrationResolver $resolver,
        private readonly PromptRenderer $promptRenderer,
        private readonly UsageGuard $guard,
        // Optional + lazily resolved so existing 4-arg construction stays valid.
        private readonly ?ConversationStore $conversationStore = null,
    ) {}

    private function store(): ConversationStore
    {
        return $this->conversationStore ?? app(ConversationStore::class);
    }

    /**
     * Stateful, multi-turn entry point. Loads (by uuid) or starts a thread,
     * renders the system prompt FRESH from the active version, composes
     * system + stored history + the new user message, dispatches, then persists
     * the user + assistant turns. Returns an AiResult carrying the thread uuid.
     *
     * A null $conversationId starts a new thread. A supplied id is validated
     * strictly — wrong integration/caller, closed, or expired all report a
     * uniform "Conversation not found" so a guessed id leaks nothing.
     *
     * @param  array<string,mixed>  $args
     * @param  array<string,mixed>  $opts
     *
     * @throws \RuntimeException When the integration or conversation is unavailable.
     * @throws InvalidPromptArgsException
     */
    public function converse(
        string $slug,
        ?string $conversationId,
        string $userMessage,
        array $args = [],
        array $opts = [],
    ): AiResult {
        $integration = $this->requireIntegration($slug);
        $version = $integration->activeVersion;

        $callerType = (string) ($opts['_caller_type'] ?? 'internal');
        $callerId = is_scalar($opts['_caller_id'] ?? null) ? (string) $opts['_caller_id'] : null;

        $store = $this->store();

        if ($conversationId === null) {
            $conversation = $store->start($integration, $callerType, $callerId, $integration->conversation_ttl_minutes);
        } else {
            /** @var class-string<AiConversation> $model */
            $model = config('ai-gateway.models.conversation', AiConversation::class);
            $conversation = $model::query()->where('uuid', $conversationId)->first();

            if ($conversation === null
                || $conversation->ai_integration_id !== $integration->id
                || $conversation->caller_type !== $callerType
                || $conversation->caller_id !== $callerId
                || $conversation->status === AiConversation::STATUS_CLOSED
                || $conversation->isExpired()
            ) {
                throw new \RuntimeException('Conversation not found');
            }
        }

        $content = $this->renderForVersion($integration, $version, $args, $opts);

        $messages = array_merge(
            [['role' => 'system', 'content' => $content]],
            $store->history($conversation),
            [['role' => 'user', 'content' => $userMessage]],
        );

        $result = $this->dispatch($integration, $messages, $opts, skipPrompt: true);

        $store->append($conversation, 'user', $userMessage, null);
        $store->append($conversation, 'assistant', $result->text, $result->invocation_id);

        return $result->withConversation($conversation->uuid);
    }

    /**
     * Open a new conversation thread without sending a turn (the `/start` call).
     *
     * @param  array<string,mixed>  $opts  May carry _caller_type / _caller_id.
     *
     * @throws \RuntimeException When the integration is missing/inactive.
     */
    public function startConversation(string $slug, array $opts = []): AiConversation
    {
        $integration = $this->requireIntegration($slug);
        $callerType = (string) ($opts['_caller_type'] ?? 'internal');
        $callerId = is_scalar($opts['_caller_id'] ?? null) ? (string) $opts['_caller_id'] : null;

        return $this->store()->start($integration, $callerType, $callerId, $integration->conversation_ttl_minutes);
    }

    /**
     * Render the integration's prompt template against $args and dispatch.
     *
     *   - $messages empty     -> [{role:user, content:rendered}]
     *   - $messages non-empty -> [{role:system, content:rendered}, ...messages]
     *
     * @param  array<string,mixed>  $args
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<string,mixed>  $opts
     *
     * @throws \RuntimeException When the integration is missing/inactive.
     * @throws InvalidPromptArgsException
     */
    public function invoke(string $slug, array $args = [], array $messages = [], array $opts = []): AiResult
    {
        $integration = $this->requireIntegration($slug);
        $version = $integration->activeVersion;

        $content = $this->renderForVersion($integration, $version, $args, $opts);

        $composed = $messages === []
            ? [['role' => 'user', 'content' => $content]]
            : array_merge([['role' => 'system', 'content' => $content]], $messages);

        return $this->dispatch($integration, $composed, $opts, skipPrompt: true);
    }

    /**
     * Workhorse: dispatch a raw messages array. The version's static system
     * prompt is prepended unless the caller already supplied a system message.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<string,mixed>  $opts
     *
     * @throws \RuntimeException
     */
    public function chat(string $slug, array $messages, array $opts = []): AiResult
    {
        $integration = $this->requireIntegration($slug);

        return $this->dispatch($integration, $messages, $opts, skipPrompt: false);
    }

    /**
     * Convenience wrapper: system prompt + single user message.
     *
     * @param  array<string,mixed>  $opts
     */
    public function complete(string $slug, string $system, string $user, array $opts = []): AiResult
    {
        return $this->chat($slug, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], $opts);
    }

    /**
     * Invoke an EXPLICIT version without persisting/activating anything — the
     * admin "test this draft" path. The version is bound in-memory only.
     *
     * @param  array<string,mixed>  $args
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<string,mixed>  $opts
     */
    public function invokeVersion(
        AiIntegration $integration,
        AiIntegrationVersion $version,
        array $args = [],
        array $messages = [],
        array $opts = [],
    ): AiResult {
        $integration->setRelation('activeVersion', $version);

        $content = $this->renderForVersion($integration, $version, $args, $opts);

        $composed = $messages === []
            ? [['role' => 'user', 'content' => $content]]
            : array_merge([['role' => 'system', 'content' => $content]], $messages);

        return $this->dispatch($integration, $composed, $opts, skipPrompt: true);
    }

    // -------------------------------------------------------------------------

    private function requireIntegration(string $slug): AiIntegration
    {
        $integration = $this->resolver->resolve($slug);

        if ($integration === null) {
            throw new \RuntimeException('AI integration not available');
        }
        if ($integration->activeVersion === null) {
            throw new \RuntimeException('AI integration has no active version');
        }

        return $integration;
    }

    /**
     * Render a version's template, recording a status=error row on validation
     * failure so the audit trail stays complete.
     *
     * @param  array<string,mixed>  $args
     * @param  array<string,mixed>  $opts
     */
    private function renderForVersion(
        AiIntegration $integration,
        AiIntegrationVersion $version,
        array $args,
        array $opts,
    ): string|array {
        $schema = is_array($version->prompt_args) ? $version->prompt_args : [];
        $template = (string) ($version->system_prompt ?? '');

        try {
            $rendered = $this->promptRenderer->render($template, $args, $schema);
        } catch (InvalidPromptArgsException $e) {
            $this->recordInvocation(
                integration: $integration,
                callerType: (string) ($opts['_caller_type'] ?? 'internal'),
                callerId: is_scalar($opts['_caller_id'] ?? null) ? (string) $opts['_caller_id'] : null,
                modelRequested: $version->models[0] ?? null,
                modelUsed: null,
                attempts: 0,
                usage: [],
                costUsd: null,
                latencyMs: 0,
                status: 'error',
                errorClass: InvalidPromptArgsException::class,
                errorMessage: $this->summarizeValidationErrors($e),
                generationId: null,
                requestHash: hash('sha256', $template.'|'.json_encode($args)),
            );

            throw $e;
        }

        return $this->formatCacheablePrompt($rendered, (bool) $version->system_prompt_cacheable);
    }

    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<string,mixed>  $opts
     */
    private function dispatch(AiIntegration $integration, array $messages, array $opts, bool $skipPrompt): AiResult
    {
        $callerType = (string) ($opts['_caller_type'] ?? 'internal');
        $callerId = is_scalar($opts['_caller_id'] ?? null) ? (string) $opts['_caller_id'] : null;
        unset($opts['_caller_type'], $opts['_caller_id']);

        // Guardrails run before we spend anything.
        $this->guard->assert($integration, $callerType, $callerId);

        $timeoutSeconds = $this->resolveRequestTimeout($integration, $opts);

        if (! $skipPrompt) {
            $messages = $this->maybePrependSystemPrompt($integration, $messages);
        }

        $payload = $this->buildPayload($integration, $messages, $opts);
        $requestHash = $this->hashRequest($payload);
        $startedAt = (int) round(microtime(true) * 1000);

        try {
            $response = $this->client->chat($payload['messages'], $this->stripMessages($payload), $timeoutSeconds);
        } catch (Throwable $e) {
            $latencyMs = ((int) round(microtime(true) * 1000)) - $startedAt;

            $errGenerationId = null;
            $errUsage = [];
            $errCost = null;
            if ($e instanceof OpenRouterRequestException) {
                $errGenerationId = $e->generationId();
                $body = $e->body();
                if (is_array($body) && isset($body['usage']) && is_array($body['usage'])) {
                    $errUsage = $this->normalizeUsage($body['usage']);
                    $errCost = $this->extractCost($body);
                }
            }

            $this->recordInvocation(
                integration: $integration,
                callerType: $callerType,
                callerId: $callerId,
                modelRequested: $payload['models'][0] ?? ($payload['model'] ?? null),
                modelUsed: null,
                attempts: 1,
                usage: $errUsage,
                costUsd: $errCost,
                latencyMs: $latencyMs,
                status: 'error',
                errorClass: $e::class,
                errorMessage: $e->getMessage(),
                generationId: $errGenerationId,
                requestHash: $requestHash,
            );

            throw $e;
        }

        $latencyMs = ((int) round(microtime(true) * 1000)) - $startedAt;

        $modelUsed = (string) ($response['model'] ?? ($payload['models'][0] ?? ($payload['model'] ?? '')));
        $modelRequested = $payload['models'][0] ?? ($payload['model'] ?? null);
        $usage = $this->normalizeUsage($response['usage'] ?? []);
        $costUsd = $this->extractCost($response);
        $finishReason = $response['choices'][0]['finish_reason'] ?? null;
        $assistantMessage = $response['choices'][0]['message'] ?? [];
        $generationId = $response['_meta']['generation_id'] ?? null;
        $citations = $this->extractCitations($response);
        $status = $this->resolveStatus($modelUsed, $payload);

        $invocationId = $this->recordInvocation(
            integration: $integration,
            callerType: $callerType,
            callerId: $callerId,
            modelRequested: $modelRequested,
            modelUsed: $modelUsed !== '' ? $modelUsed : null,
            attempts: 1,
            usage: $usage,
            costUsd: $costUsd,
            latencyMs: $latencyMs,
            status: $status,
            errorClass: null,
            errorMessage: null,
            generationId: $generationId,
            requestHash: $requestHash,
            citationCount: count($citations),
        );

        return new AiResult(
            text: $this->client->extractText($response),
            messages: is_array($assistantMessage) ? $assistantMessage : [],
            citations: $citations,
            usage: $usage,
            model_used: $modelUsed,
            provider_used: (string) $integration->provider,
            finish_reason: is_string($finishReason) ? $finishReason : null,
            cost_usd: $costUsd,
            latency_ms: $latencyMs,
            attempts: [['model' => $modelUsed, 'status' => $status, 'latency_ms' => $latencyMs]],
            generation_id: $generationId,
            invocation_id: $invocationId,
        );
    }

    private function summarizeValidationErrors(InvalidPromptArgsException $e): string
    {
        $lines = [];
        foreach ($e->errors() as $field => $messages) {
            foreach ((array) $messages as $msg) {
                $lines[] = $field.': '.$msg;
            }
        }

        return implode('; ', $lines) ?: $e->getMessage();
    }

    /**
     * Normalize web-search sources (annotations / top-level citations /
     * search_results) into a single url-deduped flat list.
     *
     * @param  array<string,mixed>  $response
     * @return array<int,array{url:string,title:?string}>
     */
    private function extractCitations(array $response): array
    {
        $candidates = [];

        $annotations = $response['choices'][0]['message']['annotations'] ?? [];
        foreach (is_array($annotations) ? $annotations : [] as $annotation) {
            if (! is_array($annotation) || ($annotation['type'] ?? null) !== 'url_citation') {
                continue;
            }
            $citation = $annotation['url_citation'] ?? null;
            if (is_array($citation)) {
                $candidates[] = ['url' => $citation['url'] ?? null, 'title' => $citation['title'] ?? null];
            }
        }

        foreach (is_array($response['citations'] ?? null) ? $response['citations'] : [] as $url) {
            if (is_string($url)) {
                $candidates[] = ['url' => $url, 'title' => null];
            }
        }

        foreach (is_array($response['search_results'] ?? null) ? $response['search_results'] : [] as $result) {
            if (is_array($result)) {
                $candidates[] = ['url' => $result['url'] ?? null, 'title' => $result['title'] ?? null];
            }
        }

        $citations = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $url = $candidate['url'];
            if (! is_string($url) || $url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $title = $candidate['title'];
            $citations[] = ['url' => $url, 'title' => is_string($title) && $title !== '' ? $title : null];
        }

        return $citations;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function resolveStatus(string $modelUsed, array $payload): string
    {
        if ($modelUsed === '') {
            return 'ok';
        }

        $candidates = $payload['models'] ?? array_filter([$payload['model'] ?? null]);
        if (! is_array($candidates) || $candidates === []) {
            return 'ok';
        }

        $usedKey = $this->canonicalModelKey($modelUsed);
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $this->canonicalModelKey($candidate) === $usedKey) {
                return 'ok';
            }
        }

        return 'fallback';
    }

    private function canonicalModelKey(?string $slug): ?string
    {
        if ($slug === null) {
            return null;
        }
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }
        $colon = strpos($slug, ':');
        if ($colon !== false) {
            $slug = substr($slug, 0, $colon);
        }

        [$vendor, $model] = array_pad(explode('/', $slug, 2), 2, '');
        if ($model === '') {
            [$vendor, $model] = ['', $vendor];
        }

        $model = (string) preg_replace('/-\d{6,8}$/', '', $model);
        $tokens = preg_split('/[.\-_]+/', $model, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($tokens);

        return $vendor.'/'.implode('-', $tokens);
    }

    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @return array<int,array<string,mixed>>
     */
    private function maybePrependSystemPrompt(AiIntegration $integration, array $messages): array
    {
        $version = $integration->activeVersion;
        $systemPrompt = $version?->system_prompt;
        if (! is_string($systemPrompt) || trim($systemPrompt) === '') {
            return $messages;
        }
        if (($messages[0]['role'] ?? null) === 'system') {
            return $messages;
        }

        $content = $this->formatCacheablePrompt($systemPrompt, (bool) $version->system_prompt_cacheable);
        array_unshift($messages, ['role' => 'system', 'content' => $content]);

        return $messages;
    }

    /**
     * @return string|array<int,array<string,mixed>>
     */
    private function formatCacheablePrompt(string $text, bool $cacheable): string|array
    {
        if (! $cacheable) {
            return $text;
        }

        return [['type' => 'text', 'text' => $text, 'cache_control' => ['type' => 'ephemeral']]];
    }

    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<string,mixed>  $opts
     * @return array<string,mixed>
     */
    private function buildPayload(AiIntegration $integration, array $messages, array $opts): array
    {
        $version = $integration->activeVersion;
        $models = is_array($version?->models) ? array_values($version->models) : [];
        if ($models === []) {
            throw new \RuntimeException('AI integration has no configured models');
        }

        $ownedKeys = ['models', 'messages', 'provider', 'usage', 'model', 'timeout'];
        $defaults = $this->stripOwnedKeys(is_array($version->default_params) ? $version->default_params : [], $ownedKeys);
        $callerOpts = $this->stripOwnedKeys($opts, $ownedKeys);

        // Clamp caller max_tokens to the configured ceiling.
        $ceiling = (int) config('ai-gateway.invocations.max_tokens_ceiling', 8192);
        foreach (['max_tokens'] as $k) {
            if (isset($callerOpts[$k]) && is_numeric($callerOpts[$k])) {
                $callerOpts[$k] = min((int) $callerOpts[$k], $ceiling);
            }
        }

        $merged = array_merge($defaults, $callerOpts);
        // Drop unset params — null or empty string (the params editor may seed a
        // model's tunable keys with blank values the admin never filled in).
        $merged = array_filter($merged, static fn ($v) => $v !== null && $v !== '');
        $merged = $this->parseJsonStrings($merged);

        $serverTools = $this->buildServerTools($version);
        if ($serverTools !== []) {
            $existingTools = (isset($merged['tools']) && is_array($merged['tools'])) ? array_values($merged['tools']) : [];
            $merged['tools'] = array_merge($existingTools, $serverTools);
        }

        $provider = ['data_collection' => 'deny', 'allow_fallbacks' => false];
        if ($serverTools === []) {
            $provider['require_parameters'] = true;
        }

        $payload = array_merge($merged, [
            'messages' => $messages,
            'provider' => $provider,
            'usage' => ['include' => true],
        ]);

        if ($serverTools === []) {
            $payload['models'] = $models;
        } else {
            $payload['model'] = $models[0];
        }

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildServerTools(?AiIntegrationVersion $version): array
    {
        $config = is_array($version?->server_tools) ? $version->server_tools : [];
        if ($config === []) {
            return [];
        }

        $spec = [
            'web_search' => ['engine', 'max_results', 'max_total_results', 'search_context_size', 'allowed_domains', 'excluded_domains'],
            'web_fetch' => ['engine', 'max_uses', 'max_content_tokens', 'allowed_domains', 'blocked_domains'],
        ];

        $tools = [];
        foreach ($spec as $tool => $paramKeys) {
            $toolConfig = $config[$tool] ?? null;
            if (! is_array($toolConfig) || empty($toolConfig['enabled'])) {
                continue;
            }
            $parameters = [];
            foreach ($paramKeys as $key) {
                if (! array_key_exists($key, $toolConfig)) {
                    continue;
                }
                $value = $toolConfig[$key];
                if ($value === null || (is_array($value) && $value === [])) {
                    continue;
                }
                $parameters[$key] = $value;
            }
            $entry = ['type' => 'openrouter:'.$tool];
            if ($parameters !== []) {
                $entry['parameters'] = $parameters;
            }
            $tools[] = $entry;
        }

        return $tools;
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolveRequestTimeout(AiIntegration $integration, array $opts): ?int
    {
        $defaults = is_array($integration->activeVersion?->default_params) ? $integration->activeVersion->default_params : [];
        $value = $opts['timeout'] ?? ($defaults['timeout'] ?? null);
        if (! is_numeric($value)) {
            return null;
        }
        $seconds = (int) $value;

        return $seconds <= 0 ? null : min($seconds, 600);
    }

    /**
     * @param  array<string,mixed>  $bag
     * @param  array<int,string>  $ownedKeys
     * @return array<string,mixed>
     */
    private function stripOwnedKeys(array $bag, array $ownedKeys): array
    {
        foreach ($ownedKeys as $key) {
            unset($bag[$key]);
        }

        return $bag;
    }

    /**
     * @param  array<string,mixed>  $bag
     * @return array<string,mixed>
     */
    private function parseJsonStrings(array $bag): array
    {
        foreach ($bag as $key => $value) {
            if (! is_string($value)) {
                continue;
            }
            $trimmed = ltrim($value);
            if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
                continue;
            }
            $decoded = json_decode($value, true);
            if ($decoded !== null || strtolower($trimmed) === 'null') {
                $bag[$key] = $decoded;
            }
        }

        return $bag;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function stripMessages(array $payload): array
    {
        unset($payload['messages']);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $usage
     * @return array<string,int|null>
     */
    private function normalizeUsage(array $usage): array
    {
        $prompt = $usage['prompt_tokens'] ?? null;
        $completion = $usage['completion_tokens'] ?? null;
        $total = $usage['total_tokens'] ?? null;
        $cached = $usage['cached_tokens'] ?? ($usage['prompt_tokens_details']['cached_tokens'] ?? null);

        return [
            'prompt_tokens' => is_numeric($prompt) ? (int) $prompt : null,
            'completion_tokens' => is_numeric($completion) ? (int) $completion : null,
            'cached_tokens' => is_numeric($cached) ? (int) $cached : null,
            'total_tokens' => is_numeric($total) ? (int) $total : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $response
     */
    private function extractCost(array $response): ?float
    {
        $cost = $response['usage']['cost'] ?? null;
        if (is_numeric($cost)) {
            return (float) $cost;
        }
        $alt = $response['usage']['total_cost'] ?? null;

        return is_numeric($alt) ? (float) $alt : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashRequest(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json !== false ? $json : serialize($payload));
    }

    /**
     * @param  array<string,int|null>  $usage
     */
    private function recordInvocation(
        AiIntegration $integration,
        string $callerType,
        ?string $callerId,
        ?string $modelRequested,
        ?string $modelUsed,
        int $attempts,
        array $usage,
        ?float $costUsd,
        ?int $latencyMs,
        string $status,
        ?string $errorClass,
        ?string $errorMessage,
        ?string $generationId,
        string $requestHash,
        ?int $citationCount = null,
    ): ?int {
        try {
            /** @var class-string<AiInvocation> $model */
            $model = config('ai-gateway.models.invocation', AiInvocation::class);

            $row = $model::create([
                'ai_integration_id' => $integration->id,
                'integration_slug_snapshot' => $integration->slug,
                'caller_type' => $callerType,
                'caller_id' => $callerId,
                'model_requested' => $modelRequested,
                'model_used' => $modelUsed,
                'attempts' => $attempts,
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'cached_tokens' => $usage['cached_tokens'] ?? null,
                'citation_count' => $citationCount,
                'cost_usd' => $costUsd,
                'latency_ms' => $latencyMs,
                'status' => $status,
                'error_class' => $errorClass,
                'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 4000) : null,
                'openrouter_generation_id' => $generationId,
                'request_hash' => $requestHash,
                'created_at' => now(),
            ]);

            return (int) $row->id;
        } catch (Throwable $e) {
            Log::warning('AiGateway: failed to persist invocation row', [
                'error' => $e->getMessage(),
                'integration_id' => $integration->id,
                'status' => $status,
            ]);

            return null;
        }
    }
}
