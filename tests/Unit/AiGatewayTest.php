<?php

declare(strict_types=1);

use Andre\AiGateway\Exceptions\InvalidPromptArgsException;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Models\AiInvocation;
use Andre\AiGateway\Services\AiGateway;
use Andre\AiGateway\Services\AiResult;
use Illuminate\Support\Facades\Http;

/**
 * Build a realistic OpenAI-style chat-completion body.
 *
 * @param  array<string,mixed>  $overrides
 * @return array<string,mixed>
 */
function chatCompletionBody(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 'gen-abc123',
        'model' => 'anthropic/claude-sonnet-4',
        'choices' => [
            [
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'The answer is 42.',
                ],
            ],
        ],
        'usage' => [
            'prompt_tokens' => 12,
            'completion_tokens' => 7,
            'total_tokens' => 19,
            'cost' => 0.0034,
        ],
    ], $overrides);
}

/**
 * Fake the OpenRouter completions endpoint with the given body + headers.
 *
 * @param  array<string,mixed>  $body
 */
function fakeOpenRouter(array $body, array $headers = ['X-Generation-Id' => 'gen-abc123']): void
{
    Http::fake([
        'openrouter.ai/api/v1/chat/completions' => Http::response($body, 200, $headers),
    ]);
}

beforeEach(function (): void {
    $this->gateway = app(AiGateway::class);
});

it('renders the prompt and returns an AiResult with text, model and cost', function (): void {
    fakeOpenRouter(chatCompletionBody());

    $integration = AiIntegration::factory()->public()->withVersion()->create();

    $result = $this->gateway->invoke($integration->slug, ['question' => 'What is the meaning of life?']);

    expect($result)->toBeInstanceOf(AiResult::class)
        ->and($result->text)->toBe('The answer is 42.')
        ->and($result->model_used)->toBe('anthropic/claude-sonnet-4')
        ->and($result->cost_usd)->toBe(0.0034)
        ->and($result->usage['prompt_tokens'])->toBe(12)
        ->and($result->usage['completion_tokens'])->toBe(7);
});

it('writes an ok invocation row on success', function (): void {
    fakeOpenRouter(chatCompletionBody());

    $integration = AiIntegration::factory()->withVersion()->create();

    $this->gateway->invoke($integration->slug, ['question' => 'hi']);

    $row = AiInvocation::query()->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('ok')
        ->and($row->ai_integration_id)->toBe($integration->id)
        ->and($row->model_used)->toBe('anthropic/claude-sonnet-4')
        ->and((float) $row->cost_usd)->toBe(0.0034);
});

it('renders the prompt template into the outgoing user message', function (): void {
    fakeOpenRouter(chatCompletionBody());

    $integration = AiIntegration::factory()->withVersion()->create();

    $this->gateway->invoke($integration->slug, ['question' => 'PINEAPPLE?']);

    Http::assertSent(function ($request): bool {
        // The factory's template is cacheable, so content is a structured block.
        $content = $request['messages'][0]['content'];
        $text = is_array($content) ? ($content[0]['text'] ?? '') : $content;

        return str_contains((string) $text, 'PINEAPPLE?');
    });
});

it('throws on a bad arg AND still records a status=error row', function (): void {
    fakeOpenRouter(chatCompletionBody());

    $integration = AiIntegration::factory()->withVersion()->create();

    // `question` is a required string; an integer is a type mismatch.
    expect(fn () => $this->gateway->invoke($integration->slug, ['question' => 12345]))
        ->toThrow(InvalidPromptArgsException::class);

    $row = AiInvocation::query()->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('error')
        ->and($row->error_class)->toBe(InvalidPromptArgsException::class);

    // A validation failure never reaches OpenRouter.
    Http::assertNothingSent();
});

it('marks the invocation as a fallback when a different model answers', function (): void {
    // Configured model is anthropic/claude-sonnet-4; OpenRouter falls back.
    fakeOpenRouter(chatCompletionBody(['model' => 'openai/gpt-4o']));

    $integration = AiIntegration::factory()->withVersion()->create();

    $result = $this->gateway->invoke($integration->slug, ['question' => 'hi']);

    expect($result->model_used)->toBe('openai/gpt-4o');

    $row = AiInvocation::query()->latest('id')->first();

    expect($row->status)->toBe('fallback');
});

it('clamps a caller max_tokens above the ceiling down to the ceiling', function (): void {
    config()->set('ai-gateway.invocations.max_tokens_ceiling', 4096);

    fakeOpenRouter(chatCompletionBody());

    $integration = AiIntegration::factory()->withVersion()->create();

    $this->gateway->invoke(
        $integration->slug,
        ['question' => 'hi'],
        [],
        ['max_tokens' => 999999],
    );

    Http::assertSent(fn ($request): bool => ($request['max_tokens'] ?? null) === 4096);
});

it('does not raise a caller max_tokens that is already under the ceiling', function (): void {
    config()->set('ai-gateway.invocations.max_tokens_ceiling', 4096);

    fakeOpenRouter(chatCompletionBody());

    $integration = AiIntegration::factory()->withVersion()->create();

    $this->gateway->invoke(
        $integration->slug,
        ['question' => 'hi'],
        [],
        ['max_tokens' => 256],
    );

    Http::assertSent(fn ($request): bool => ($request['max_tokens'] ?? null) === 256);
});
