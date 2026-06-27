<?php

declare(strict_types=1);

use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Support\Settings;
use Andre\AiGateway\Tests\Fixtures\User;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0:User,1:string}
 */
function userWithToken(array $abilities): array
{
    $user = User::query()->create([
        'name' => 'Tester',
        'email' => 'tester'.uniqid().'@example.test',
        'password' => bcrypt('secret'),
    ]);

    $token = $user->createToken('t', $abilities)->plainTextToken;

    return [$user, $token];
}

function fakeChat(): void
{
    Http::fake([
        'openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'gen-http',
            'model' => 'anthropic/claude-sonnet-4',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => 'Hello from the gateway.'],
            ]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 4, 'cost' => 0.0002],
        ], 200, ['X-Generation-Id' => 'gen-http']),
    ]);
}

beforeEach(function (): void {
    Settings::flush();
    $ability = config('ai-gateway.api.token_ability');
    [$this->user, $this->token] = userWithToken([$ability]);
});

it('invokes a public integration and returns the assistant text', function (): void {
    fakeChat();

    $integration = AiIntegration::factory()->public()->withVersion()->create();

    $response = $this->withToken($this->token)
        ->postJson("/api/ai/{$integration->slug}/chat", [
            'args' => ['question' => 'Hi there?'],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.text', 'Hello from the gateway.')
        ->assertJsonPath('meta.integration', $integration->slug);
});

it('returns 404 for an unknown slug', function (): void {
    fakeChat();

    $response = $this->withToken($this->token)
        ->postJson('/api/ai/does-not-exist/chat', ['args' => []]);

    $response->assertNotFound();
});

it('returns 409 for an internal-visibility integration', function (): void {
    fakeChat();

    // Default factory visibility is "internal" → not publicly invocable.
    $integration = AiIntegration::factory()->withVersion()->create();

    $response = $this->withToken($this->token)
        ->postJson("/api/ai/{$integration->slug}/chat", ['args' => ['question' => 'hi']]);

    $response->assertStatus(409);
});

it('returns 404 when the API is disabled at runtime', function (): void {
    fakeChat();

    Settings::set('api_enabled', false);

    $integration = AiIntegration::factory()->public()->withVersion()->create();

    $response = $this->withToken($this->token)
        ->postJson("/api/ai/{$integration->slug}/chat", ['args' => ['question' => 'hi']]);

    $response->assertNotFound();

    Settings::set('api_enabled', true);
});

it('rejects an unauthenticated request with 401', function (): void {
    fakeChat();

    $integration = AiIntegration::factory()->public()->withVersion()->create();

    $response = $this->postJson("/api/ai/{$integration->slug}/chat", ['args' => ['question' => 'hi']]);

    $response->assertUnauthorized();
});

it('rejects a token that lacks the invoke ability with 403', function (): void {
    fakeChat();

    [, $wrongToken] = userWithToken(['some-other-ability']);

    $integration = AiIntegration::factory()->public()->withVersion()->create();

    $response = $this->withToken($wrongToken)
        ->postJson("/api/ai/{$integration->slug}/chat", ['args' => ['question' => 'hi']]);

    $response->assertForbidden();
});

it('returns 422 with an errors bag when required args are missing', function (): void {
    fakeChat();

    $integration = AiIntegration::factory()->public()->withVersion()->create();

    // The factory version requires `question`; omit it.
    $response = $this->withToken($this->token)
        ->postJson("/api/ai/{$integration->slug}/chat", ['args' => []]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);
});
