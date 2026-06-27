<?php

declare(strict_types=1);

namespace Andre\AiGateway\Http\Controllers;

use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Services\AiGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST {prefix}/{integration}/start — open a conversation thread, returns its
 * id. Only for integrations flagged `is_conversational` and HTTP-visible.
 */
class StartConversationController
{
    public function __invoke(Request $request, AiGateway $gateway, string $integration): JsonResponse
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

        try {
            $conversation = $gateway->startConversation($integration, [
                '_caller_type' => 'api',
                '_caller_id' => CallerId::resolve($request),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Integration not available'], 409);
        }

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->uuid,
                'expires_at' => $conversation->expires_at?->toIso8601String(),
            ],
            'meta' => ['integration' => $integration],
        ], 201);
    }
}
