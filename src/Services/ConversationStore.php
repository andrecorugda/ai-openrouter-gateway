<?php

declare(strict_types=1);

namespace Andre\AiGateway\Services;

use Andre\AiGateway\Models\AiConversation;
use Andre\AiGateway\Models\AiConversationMessage;
use Andre\AiGateway\Models\AiIntegration;
use Illuminate\Support\Carbon;

/**
 * Persistence layer for conversation threads. Owns thread lifecycle
 * (start / append / touch / close) and the `expires_at` computation from the
 * integration's `conversation_ttl_minutes` (falling back to
 * `config('ai-gateway.conversations.default_ttl_minutes')`). The gateway
 * composes this with the stateless dispatch path in {@see AiGateway::converse()}.
 */
class ConversationStore
{
    /**
     * Open a new thread for an integration.
     *
     * @param  array<string,mixed>  $metadata
     */
    public function start(
        AiIntegration $integration,
        string $callerType,
        ?string $callerId,
        ?int $ttlMinutes,
        array $metadata = [],
    ): AiConversation {
        $ttl = $ttlMinutes ?? (int) config('ai-gateway.conversations.default_ttl_minutes', 2880);
        $now = Carbon::now();

        /** @var class-string<AiConversation> $model */
        $model = config('ai-gateway.models.conversation', AiConversation::class);

        return $model::create([
            'ai_integration_id' => $integration->id,
            'caller_type' => $callerType,
            'caller_id' => $callerId,
            'status' => AiConversation::STATUS_ACTIVE,
            'metadata' => $metadata === [] ? null : $metadata,
            'last_activity_at' => $now,
            'expires_at' => $now->copy()->addMinutes($ttl),
            'message_count' => 0,
        ]);
    }

    /**
     * The thread's turns as OpenAI-style messages, oldest first.
     *
     * @return array<int,array{role:string,content:string}>
     */
    public function history(AiConversation $conversation): array
    {
        $out = [];
        foreach ($conversation->messages()->get() as $message) {
            /** @var AiConversationMessage $message */
            $out[] = [
                'role' => (string) $message->role,
                'content' => (string) $message->content,
            ];
        }

        return $out;
    }

    /**
     * Append one turn; bumps message_count + last_activity_at on the thread.
     */
    public function append(
        AiConversation $conversation,
        string $role,
        string $content,
        ?int $invocationId = null,
    ): AiConversationMessage {
        /** @var class-string<AiConversationMessage> $model */
        $model = config('ai-gateway.models.conversation_message', AiConversationMessage::class);

        $message = $model::create([
            'ai_conversation_id' => $conversation->id,
            'ai_invocation_id' => $invocationId,
            'role' => $role,
            'content' => $content,
            'created_at' => Carbon::now(),
        ]);

        $conversation->increment('message_count');
        $conversation->forceFill(['last_activity_at' => Carbon::now()])->save();

        return $message;
    }

    public function touch(AiConversation $conversation): void
    {
        $conversation->forceFill(['last_activity_at' => Carbon::now()])->save();
    }

    public function close(AiConversation $conversation): void
    {
        $conversation->forceFill(['status' => AiConversation::STATUS_CLOSED])->save();
    }
}
