<?php

declare(strict_types=1);

namespace Andre\AiGateway\Http\Controllers;

use Andre\AiGateway\Exceptions\CostLimitExceededException;
use Andre\AiGateway\Exceptions\InvalidPromptArgsException;
use Andre\AiGateway\Exceptions\OpenRouterRequestException;
use Andre\AiGateway\Exceptions\RateLimitExceededException;
use Andre\AiGateway\Http\Requests\InvokeAiIntegrationRequest;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Services\AiGateway;
use Illuminate\Http\JsonResponse;

/**
 * Public invocation endpoint: POST {prefix}/{integration}/chat.
 *
 * Authenticated via a Sanctum token carrying the configured ability. The
 * authenticated token id is recorded as the invocation caller for per-token
 * usage + cost attribution.
 */
class InvokeAiIntegrationController
{
    public function __invoke(InvokeAiIntegrationRequest $request, AiGateway $gateway, string $integration): JsonResponse
    {
        /** @var class-string<AiIntegration> $model */
        $model = config('ai-gateway.models.integration', AiIntegration::class);

        /** @var AiIntegration|null $row */
        $row = $model::query()->where('slug', $integration)->first();

        if ($row === null) {
            return response()->json(['message' => 'Integration not found'], 404);
        }
        if (! $row->is_active || ! $row->isPubliclyInvocable()) {
            return response()->json(['message' => 'Integration not available'], 409);
        }

        $opts = $request->options();
        $opts['_caller_type'] = 'api';
        $opts['_caller_id'] = $this->callerId($request);

        try {
            $result = $gateway->invoke(
                slug: $integration,
                args: (array) $request->input('args', []),
                messages: (array) $request->input('messages', []),
                opts: $opts,
            );
        } catch (InvalidPromptArgsException $e) {
            return response()->json(['message' => 'Invalid arguments', 'errors' => $e->errors()], 422);
        } catch (RateLimitExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 429)
                ->withHeaders($e->retryAfterSeconds !== null ? ['Retry-After' => (string) $e->retryAfterSeconds] : []);
        } catch (CostLimitExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        } catch (OpenRouterRequestException $e) {
            return response()->json(['message' => 'AI provider request failed'], 502);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Integration not available'], 409);
        }

        return response()->json([
            'data' => $result->toArray(),
            'meta' => ['integration' => $integration],
        ]);
    }

    private function callerId(InvokeAiIntegrationRequest $request): ?string
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token !== null && isset($token->id)) {
            return 'token:'.$token->id;
        }

        return $user?->getAuthIdentifier() !== null ? 'user:'.$user->getAuthIdentifier() : null;
    }
}
