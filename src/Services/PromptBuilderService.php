<?php

declare(strict_types=1);

namespace Andre\AiGateway\Services;

use Andre\AiGateway\Support\Settings;

/**
 * AI-assisted prompt builder.
 *
 * Given a plain-language description of what an integration should do, this
 * drafts a ready-to-use system-prompt template (with `{{variable}}`
 * placeholders) and the matching `prompt_args` schema. It runs straight through
 * OpenRouter using the gateway's own key, so it lights up the moment a key is
 * configured — no separate setup.
 *
 * The helper model defaults to a fast, cheap Haiku and is overridable from the
 * admin "General settings" page (persisted via {@see Settings}).
 */
class PromptBuilderService
{
    public function __construct(private readonly OpenRouterClient $client) {}

    public function isAvailable(): bool
    {
        return Settings::bool('prompt_builder_enabled') && $this->client->isConfigured();
    }

    public function model(): string
    {
        return Settings::string('prompt_builder_model');
    }

    /**
     * Draft a prompt template + variable schema from a natural-language brief.
     *
     * @return array{system_prompt:string, prompt_args:array<int,array<string,mixed>>, notes:?string}
     */
    public function build(string $brief, ?string $existingPrompt = null): array
    {
        $system = $this->systemPrompt();

        $user = "Task description:\n".trim($brief);
        if ($existingPrompt !== null && trim($existingPrompt) !== '') {
            $user .= "\n\nImprove this existing prompt rather than starting from scratch:\n".trim($existingPrompt);
        }

        $response = $this->client->chat(
            messages: [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            options: [
                'model' => $this->model(),
                'max_tokens' => (int) config('ai-gateway.prompt_builder.max_tokens', 2048),
                'temperature' => (float) config('ai-gateway.prompt_builder.temperature', 0.4),
                'response_format' => ['type' => 'json_object'],
                'usage' => ['include' => true],
            ],
        );

        return $this->parse($this->client->extractText($response));
    }

    private function systemPrompt(): string
    {
        $types = implode(', ', PromptRenderer::VALID_TYPES);

        return <<<PROMPT
        You are a prompt engineer helping build a reusable AI integration for a Laravel "AI Gateway".

        The user describes what the integration should do. You design:
          1. A clear, production-quality SYSTEM PROMPT template. Insert runtime inputs as
             {{snake_case}} placeholders (double curly braces). Use placeholders only for values
             that vary per call; keep stable instructions as literal text.
          2. The VARIABLE SCHEMA: one descriptor per placeholder you used.

        Rules:
          - Placeholder names match /^[a-z][a-z0-9_]*$/ and are at most 32 chars.
          - Every {{placeholder}} in the template MUST have a matching descriptor, and vice versa.
          - Each descriptor has: name (string), type (one of: {$types}), required (boolean),
            and an optional short "description".
          - Prefer "string" unless structured input is clearly needed.

        Respond with ONLY a JSON object, no markdown fences, of exactly this shape:
        {
          "system_prompt": "the template text with {{placeholders}}",
          "prompt_args": [
            {"name": "company_name", "type": "string", "required": true, "description": "..."}
          ],
          "notes": "one short sentence on how to use it, or null"
        }
        PROMPT;
    }

    /**
     * @return array{system_prompt:string, prompt_args:array<int,array<string,mixed>>, notes:?string}
     */
    private function parse(string $text): array
    {
        $text = trim($text);
        // Strip accidental markdown fences.
        $text = (string) preg_replace('/^```(?:json)?|```$/m', '', $text);
        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('The prompt builder returned an unparseable response.');
        }

        $args = [];
        foreach ((array) ($decoded['prompt_args'] ?? []) as $arg) {
            if (! is_array($arg) || ! isset($arg['name'])) {
                continue;
            }
            $args[] = [
                'name' => (string) $arg['name'],
                'type' => in_array($arg['type'] ?? null, PromptRenderer::VALID_TYPES, true) ? $arg['type'] : 'string',
                'required' => (bool) ($arg['required'] ?? false),
                'default' => $arg['default'] ?? null,
                'description' => isset($arg['description']) ? (string) $arg['description'] : null,
            ];
        }

        return [
            'system_prompt' => (string) ($decoded['system_prompt'] ?? ''),
            'prompt_args' => $args,
            'notes' => isset($decoded['notes']) && is_string($decoded['notes']) ? $decoded['notes'] : null,
        ];
    }
}
