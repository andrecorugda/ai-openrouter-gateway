<?php

declare(strict_types=1);

use Andre\AiGateway\Services\OpenRouterClient;
use Andre\AiGateway\Services\PromptBuilderService;
use Andre\AiGateway\Support\Settings;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Settings::flush();
});

/**
 * The prompt builder asks for a JSON object; OpenRouter returns it as the
 * assistant message content (a JSON string).
 */
function fakeBuilderResponse(string $content): void
{
    Http::fake([
        'openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-builder',
            'model' => 'anthropic/claude-haiku-4.5',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => $content],
            ]],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 30, 'cost' => 0.0001],
        ], 200, ['X-Generation-Id' => 'gen-builder']),
    ]);
}

it('parses the builder response into system_prompt, normalized args, and notes', function (): void {
    fakeBuilderResponse(json_encode([
        'system_prompt' => 'Hi {{name}}',
        'prompt_args' => [
            ['name' => 'name', 'type' => 'string', 'required' => true],
        ],
        'notes' => 'x',
    ]));

    $result = app(PromptBuilderService::class)->build('Greet a person by name.');

    expect($result['system_prompt'])->toBe('Hi {{name}}')
        ->and($result['notes'])->toBe('x')
        ->and($result['prompt_args'])->toHaveCount(1);

    $arg = $result['prompt_args'][0];

    expect($arg['name'])->toBe('name')
        ->and($arg['type'])->toBe('string')
        ->and($arg['required'])->toBeTrue()
        // parse() always fills these normalized keys.
        ->and($arg)->toHaveKey('default')
        ->and($arg)->toHaveKey('description');
});

it('coerces an unknown arg type to string and skips descriptors without a name', function (): void {
    fakeBuilderResponse(json_encode([
        'system_prompt' => 'Use {{thing}}',
        'prompt_args' => [
            ['name' => 'thing', 'type' => 'datetime', 'required' => false],
            ['type' => 'string', 'required' => true], // no name → skipped
        ],
        'notes' => null,
    ]));

    $result = app(PromptBuilderService::class)->build('do a thing');

    expect($result['prompt_args'])->toHaveCount(1)
        ->and($result['prompt_args'][0]['type'])->toBe('string')
        ->and($result['notes'])->toBeNull();
});

it('strips markdown fences before decoding', function (): void {
    fakeBuilderResponse("```json\n".json_encode([
        'system_prompt' => 'Fenced {{x}}',
        'prompt_args' => [['name' => 'x', 'type' => 'string', 'required' => true]],
        'notes' => null,
    ])."\n```");

    $result = app(PromptBuilderService::class)->build('fenced output');

    expect($result['system_prompt'])->toBe('Fenced {{x}}')
        ->and($result['prompt_args'])->toHaveCount(1);
});

it('throws when the response is not decodable JSON', function (): void {
    fakeBuilderResponse('this is definitely not json');

    expect(fn () => app(PromptBuilderService::class)->build('garbage'))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// isAvailable — reflects Settings + key presence
// ---------------------------------------------------------------------------

it('is available when enabled and the OpenRouter key is configured', function (): void {
    // TestCase sets the api_key to "test-key"; default enabled flag is true.
    config()->set('ai-gateway.prompt_builder.enabled', true);
    Settings::flush();

    expect(app(PromptBuilderService::class)->isAvailable())->toBeTrue();
});

it('is unavailable when the prompt builder setting is disabled', function (): void {
    config()->set('ai-gateway.prompt_builder.enabled', false);
    Settings::flush();

    expect(app(PromptBuilderService::class)->isAvailable())->toBeFalse();
});

it('is unavailable when no OpenRouter key is configured', function (): void {
    config()->set('ai-gateway.prompt_builder.enabled', true);
    config()->set('ai-gateway.openrouter.api_key', '');
    Settings::flush();

    // The client reads the key in its constructor, so build a fresh one.
    $service = new PromptBuilderService(new OpenRouterClient);

    expect($service->isAvailable())->toBeFalse();
});
