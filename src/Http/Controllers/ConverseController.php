<?php

declare(strict_types=1);

namespace Andre\AiGateway\Http\Controllers;

use Andre\AiGateway\Exceptions\CostLimitExceededException;
use Andre\AiGateway\Exceptions\InvalidPromptArgsException;
use Andre\AiGateway\Exceptions\OpenRouterRequestException;
use Andre\AiGateway\Exceptions\RateLimitExceededException;
use Andre\AiGateway\Http\Requests\ConverseRequest;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Services\AiGateway;
use Illuminate\Http\JsonResponse;

/**
 * POST {prefix}/{integration}/converse — continue (or start) a thread.
 *
 * Body: { conversation_id?, message, args?, options? }. Omit conversation_id to
 * start a fresh thread; the response always returns the (new or existing) id.
 */
class ConverseController
{
    public function __invoke(ConverseRequest $request, AiGateway $gateway, string $integration): JsonResponse
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
        if (! $row->is_conversational) {
            return response()->json(['message' => 'Integration is not conversational'], 409);
        }

        $opts = $request->options();
        $opts['_caller_type'] = 'api';
        $opts['_caller_id'] = CallerId::resolve($request);

        try {
            $result = $gateway->converse(
                slug: $integration,
                conversationId: $request->input('conversation_id') ?: null,
                userMessage: (string) $request->input('message'),
                args: (array) $request->input('args', []),
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
            // "Conversation not found" → 404; integration unavailable → 409.
            $notFound = str_contains($e->getMessage(), 'Conversation');

            return response()->json(['message' => $e->getMessage()], $notFound ? 404 : 409);
        }

        return response()->json([
            'data' => $result->toArray(),
            'meta' => ['integration' => $integration],
        ]);
    }
}
