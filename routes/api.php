<?php

declare(strict_types=1);

use Andre\AiGateway\Http\Controllers\ConverseController;
use Andre\AiGateway\Http\Controllers\InvokeAiIntegrationController;
use Andre\AiGateway\Http\Controllers\StartConversationController;
use Andre\AiGateway\Http\Middleware\EnsureApiEnabled;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
|--------------------------------------------------------------------------
| AI Gateway HTTP API
|--------------------------------------------------------------------------
|
| Registered by AiGatewayServiceProvider only when `ai-gateway.api.enabled`
| is true at boot. The EnsureApiEnabled middleware additionally honours the
| runtime toggle so an operator can turn the API off from the admin UI without
| a redeploy.
|
| Auth: Sanctum token carrying the `ai-gateway.api.token_ability` ability.
|
*/

$ability = (string) config('ai-gateway.api.token_ability', 'ai-gateway:invoke');

Route::middleware(array_merge(
    (array) config('ai-gateway.api.middleware', ['api']),
    [EnsureApiEnabled::class],
    (array) config('ai-gateway.api.auth_middleware', ['auth:sanctum']),
    // Reference Sanctum's middleware class directly rather than the `abilities`
    // alias — the alias is only registered if the host app declares it, but the
    // class resolves everywhere (and under Testbench).
    [CheckAbilities::class.':'.$ability],
))->prefix((string) config('ai-gateway.api.prefix', 'api/ai'))->group(function () {
    Route::post('{integration}/chat', InvokeAiIntegrationController::class)
        ->name('ai-gateway.invoke');

    // Multi-turn conversation threads (integrations flagged is_conversational).
    Route::post('{integration}/start', StartConversationController::class)
        ->name('ai-gateway.conversation.start');
    Route::post('{integration}/converse', ConverseController::class)
        ->name('ai-gateway.conversation.converse');
});
