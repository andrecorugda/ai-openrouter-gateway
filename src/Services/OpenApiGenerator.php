<?php

declare(strict_types=1);

namespace Andre\AiGateway\Services;

use Andre\AiGateway\Models\AiIntegration;

/**
 * Builds a live OpenAPI 3.0 document from the registered integrations.
 *
 * Every API-visible (public/both), active integration becomes a real endpoint:
 *   - POST {prefix}/{slug}/chat       — body shaped from the integration's prompt_args + options
 *   - POST {prefix}/{slug}/start      — only when is_conversational
 *   - POST {prefix}/{slug}/converse   — only when is_conversational
 *
 * The per-arg request schema is derived from the active version's prompt_args
 * (name, type, required), and the model + caching mode are surfaced in the
 * endpoint description.
 */
class OpenApiGenerator
{
    public function __construct(private readonly OpenRouterModelCatalog $catalog) {}

    /**
     * @return array<string,mixed>
     */
    public function generate(): array
    {
        $prefix = '/'.trim((string) config('ai-gateway.api.prefix', 'api/ai'), '/');
        $ability = (string) config('ai-gateway.api.token_ability', 'ai-gateway:invoke');

        $paths = [];
        $tags = [];

        foreach ($this->integrations() as $integration) {
            $tag = $integration->name ?: $integration->slug;
            $tags[] = ['name' => $tag, 'description' => (string) ($integration->description ?? '')];

            $base = $prefix.'/'.$integration->slug;
            $paths[$base.'/chat'] = ['post' => $this->chatOperation($integration, $tag)];

            if ($integration->is_conversational) {
                $paths[$base.'/start'] = ['post' => $this->startOperation($integration, $tag)];
                $paths[$base.'/converse'] = ['post' => $this->converseOperation($integration, $tag)];
            }
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => (string) config('app.name', 'Laravel').' — AI Gateway API',
                'version' => '1.0.0',
                'description' => "Authenticated with a Sanctum bearer token carrying the `{$ability}` ability. "
                    .'Each path below is a registered integration; the request body is shaped from its declared variables.',
            ],
            'servers' => [['url' => rtrim((string) config('app.url', 'http://localhost'), '/')]],
            'tags' => $tags,
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => "Sanctum token with the `{$ability}` ability."],
                ],
                'schemas' => [
                    'AiResult' => $this->resultSchema(),
                    'Error' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']]],
                    'ValidationError' => ['type' => 'object', 'properties' => [
                        'message' => ['type' => 'string'],
                        'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ]],
                ],
            ],
            'paths' => $paths,
        ];
    }

    /**
     * @return iterable<int,AiIntegration>
     */
    private function integrations(): iterable
    {
        /** @var class-string<AiIntegration> $model */
        $model = config('ai-gateway.models.integration', AiIntegration::class);

        return $model::query()->with('activeVersion')->active()->public()->orderBy('slug')->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function chatOperation(AiIntegration $integration, string $tag): array
    {
        return [
            'tags' => [$tag],
            'summary' => 'Invoke '.$integration->slug,
            'description' => $this->describe($integration),
            'operationId' => 'chat_'.$integration->slug,
            'requestBody' => $this->jsonBody([
                'type' => 'object',
                'properties' => array_filter([
                    'args' => $this->argsSchema($integration),
                    'options' => $this->optionsSchema(),
                    'messages' => $this->messagesSchema(),
                ]),
                'required' => $this->hasRequiredArgs($integration) ? ['args'] : [],
            ]),
            'responses' => $this->commonResponses(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function startOperation(AiIntegration $integration, string $tag): array
    {
        return [
            'tags' => [$tag],
            'summary' => 'Start a conversation thread',
            'description' => 'Opens a new multi-turn thread and returns its `conversation_id`. Continue it with `/converse`.',
            'operationId' => 'start_'.$integration->slug,
            'responses' => [
                '201' => [
                    'description' => 'Thread created',
                    'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => [
                        'conversation_id' => ['type' => 'string', 'format' => 'uuid'],
                        'expires_at' => ['type' => 'string', 'format' => 'date-time', 'nullable' => true],
                    ]]]]]],
                ],
                '409' => $this->errorResponse('Integration not available / not conversational'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function converseOperation(AiIntegration $integration, string $tag): array
    {
        return [
            'tags' => [$tag],
            'summary' => 'Send a turn to a conversation',
            'description' => 'Continues a thread (omit `conversation_id` to start a fresh one). The reply carries the thread id.',
            'operationId' => 'converse_'.$integration->slug,
            'requestBody' => $this->jsonBody([
                'type' => 'object',
                'properties' => array_filter([
                    'conversation_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true, 'description' => 'Omit to start a new thread.'],
                    'message' => ['type' => 'string'],
                    'args' => $this->argsSchema($integration),
                    'options' => $this->optionsSchema(),
                ]),
                'required' => ['message'],
            ]),
            'responses' => $this->commonResponses(),
        ];
    }

    private function describe(AiIntegration $integration): string
    {
        $version = $integration->activeVersion;
        $models = is_array($version?->models) ? $version->models : [];
        $primary = $models[0] ?? null;

        $lines = [];
        if ($integration->description) {
            $lines[] = (string) $integration->description;
        }
        if ($primary) {
            $caching = $this->catalog->cachingMode($primary);
            $lines[] = '**Model:** `'.implode('`, `', $models).'`';
            $lines[] = '**Prompt caching:** '.$caching;

            $params = $this->catalog->supportedParameters($primary);
            if ($params !== []) {
                $lines[] = '**Model parameters** (configured per-integration; the API accepts `options.max_tokens` and `options.temperature`): `'.implode('`, `', $params).'`';
            }
        }

        return implode("\n\n", $lines);
    }

    /**
     * @return array<string,mixed>
     */
    private function argsSchema(AiIntegration $integration): array
    {
        $args = $integration->activeVersion?->prompt_args;
        $args = is_array($args) ? $args : [];

        $properties = [];
        $required = [];
        foreach ($args as $arg) {
            if (! is_array($arg) || ! isset($arg['name'])) {
                continue;
            }
            $name = (string) $arg['name'];
            $properties[$name] = array_filter([
                'type' => $this->openApiType((string) ($arg['type'] ?? 'string')),
                'description' => isset($arg['description']) ? (string) $arg['description'] : null,
            ]);
            if (! empty($arg['required']) && ($arg['default'] ?? null) === null) {
                $required[] = $name;
            }
        }

        return array_filter([
            'type' => 'object',
            'properties' => $properties === [] ? null : $properties,
            'required' => $required === [] ? null : $required,
        ]);
    }

    private function hasRequiredArgs(AiIntegration $integration): bool
    {
        foreach ((array) $integration->activeVersion?->prompt_args as $arg) {
            if (is_array($arg) && ! empty($arg['required']) && ($arg['default'] ?? null) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function optionsSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'Per-call overrides (allow-listed).',
            'properties' => [
                'max_tokens' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Clamped to the server ceiling.'],
                'temperature' => ['type' => 'number', 'minimum' => 0, 'maximum' => 2],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function messagesSchema(): array
    {
        return [
            'type' => 'array',
            'description' => 'Optional conversation turns layered on top of the rendered prompt.',
            'items' => ['type' => 'object', 'properties' => [
                'role' => ['type' => 'string', 'enum' => ['system', 'user', 'assistant']],
                'content' => ['type' => 'string'],
            ]],
        ];
    }

    private function openApiType(string $type): string
    {
        return match ($type) {
            'number' => 'number',
            'boolean' => 'boolean',
            'array' => 'array',
            'object' => 'object',
            default => 'string', // string, json
        };
    }

    /**
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    private function jsonBody(array $schema): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /**
     * @return array<string,mixed>
     */
    private function commonResponses(): array
    {
        return [
            '200' => [
                'description' => 'Success',
                'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
                    'data' => ['$ref' => '#/components/schemas/AiResult'],
                    'meta' => ['type' => 'object', 'properties' => ['integration' => ['type' => 'string']]],
                ]]]],
            ],
            '401' => $this->errorResponse('Missing or invalid token'),
            '402' => $this->errorResponse('Daily cost limit reached'),
            '404' => $this->errorResponse('Integration not found'),
            '409' => $this->errorResponse('Integration not available'),
            '422' => ['description' => 'Validation failed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]]],
            '429' => $this->errorResponse('Rate limit exceeded'),
            '502' => $this->errorResponse('AI provider request failed'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function errorResponse(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
    }

    /**
     * @return array<string,mixed>
     */
    private function resultSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string'],
            'model_used' => ['type' => 'string'],
            'provider_used' => ['type' => 'string'],
            'finish_reason' => ['type' => 'string', 'nullable' => true],
            'usage' => ['type' => 'object', 'properties' => [
                'prompt_tokens' => ['type' => 'integer', 'nullable' => true],
                'completion_tokens' => ['type' => 'integer', 'nullable' => true],
                'cached_tokens' => ['type' => 'integer', 'nullable' => true],
                'total_tokens' => ['type' => 'integer', 'nullable' => true],
            ]],
            'cost_usd' => ['type' => 'number', 'nullable' => true],
            'latency_ms' => ['type' => 'integer', 'nullable' => true],
            'generation_id' => ['type' => 'string', 'nullable' => true],
            'invocation_id' => ['type' => 'integer', 'nullable' => true],
            'conversation_id' => ['type' => 'string', 'nullable' => true],
        ]];
    }
}
