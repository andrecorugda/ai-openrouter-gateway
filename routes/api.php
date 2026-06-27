<?php

declare(strict_types=1);

use Andre\AiGateway\Http\Controllers\InvokeAiIntegrationController;
use Andre\AiGateway\Http\Middleware\EnsureApiEnabled;
use Illuminate\Support\Facades\Route;

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
    ["abilities:{$ability}"],
))->prefix((string) config('ai-gateway.api.prefix', 'api/ai'))->group(function () {
    Route::post('{integration}/chat', InvokeAiIntegrationController::class)
        ->name('ai-gateway.invoke');
});
