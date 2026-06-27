<?php

declare(strict_types=1);

namespace Andre\AiGateway\Facades;

use Andre\AiGateway\Services\AiResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static AiResult invoke(string $slug, array $args = [], array $messages = [], array $opts = [])
 * @method static AiResult chat(string $slug, array $messages, array $opts = [])
 * @method static AiResult complete(string $slug, string $system, string $user, array $opts = [])
 * @method static AiResult converse(string $slug, ?string $conversationId, string $userMessage, array $args = [], array $opts = [])
 * @method static \Andre\AiGateway\Models\AiConversation startConversation(string $slug, array $opts = [])
 *
 * @see \Andre\AiGateway\Services\AiGateway
 */
class AiGateway extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Andre\AiGateway\Services\AiGateway::class;
    }
}
