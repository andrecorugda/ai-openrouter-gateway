<?php

declare(strict_types=1);

use Andre\AiGateway\Models\AiConversation;
use Andre\AiGateway\Models\AiConversationMessage;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Services\AiGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function fakeConversationHttp(): void
{
    Http::fake([
        '*/chat/completions' => Http::response([
            'id' => 'gen_test',
            'model' => 'anthropic/claude-haiku-4.5',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12, 'cost' => 0.0001],
        ], 200, ['X-Generation-Id' => 'gen_test']),
    ]);
}

function conversationalIntegration(): AiIntegration
{
    return AiIntegration::factory()
        ->public()
        ->withVersion()
        ->create(['is_conversational' => true, 'conversation_ttl_minutes' => 60]);
}

it('starts a thread, persists both turns, and continues it by id', function () {
    fakeConversationHttp();
    $integration = conversationalIntegration();
    $gateway = app(AiGateway::class);

    $first = $gateway->converse($integration->slug, null, 'My name is Andre', ['question' => 'hi']);

    expect($first->conversation_id)->not->toBeNull();
    expect(AiConversation::where('uuid', $first->conversation_id)->exists())->toBeTrue();

    $second = $gateway->converse($integration->slug, $first->conversation_id, 'and again', ['question' => 'hi']);

    expect($second->conversation_id)->toBe($first->conversation_id);

    // 2 turns × 2 calls = 4 stored messages (user + assistant each).
    expect(AiConversationMessage::count())->toBe(4);

    $conversation = AiConversation::where('uuid', $first->conversation_id)->first();
    expect($conversation->message_count)->toBe(4);
});

it('replays prior turns as history on the next call', function () {
    fakeConversationHttp();
    $integration = conversationalIntegration();
    $gateway = app(AiGateway::class);

    $first = $gateway->converse($integration->slug, null, 'remember teal', ['question' => 'hi']);
    $gateway->converse($integration->slug, $first->conversation_id, 'what color?', ['question' => 'hi']);

    // The second request must carry: system + (user, assistant) history + new user.
    Http::assertSent(function ($request) {
        $messages = $request->data()['messages'] ?? [];
        $roles = array_column($messages, 'role');

        return in_array('system', $roles, true)
            && count(array_keys($roles, 'user')) >= 2;
    });
});

it('rejects an unknown or non-owned conversation id', function () {
    fakeConversationHttp();
    $integration = conversationalIntegration();

    app(AiGateway::class)->converse($integration->slug, (string) Str::uuid(), 'hi', ['question' => 'hi']);
})->throws(RuntimeException::class, 'Conversation not found');

it('prunes expired conversations', function () {
    $integration = conversationalIntegration();
    AiConversation::create([
        'ai_integration_id' => $integration->id,
        'caller_type' => 'internal',
        'status' => 'active',
        'last_activity_at' => now()->subDays(3),
        'expires_at' => now()->subDay(),
        'message_count' => 0,
    ]);

    expect(AiConversation::count())->toBe(1);

    $this->artisan('ai-gateway:prune-conversations')->assertSuccessful();

    expect(AiConversation::count())->toBe(0); // soft-deleted
});
