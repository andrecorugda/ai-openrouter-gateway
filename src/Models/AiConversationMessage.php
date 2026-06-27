<?php

declare(strict_types=1);

namespace Andre\AiGateway\Models;

use Andre\AiGateway\Support\Schema as GatewaySchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One persisted turn of a conversation thread. `ai_invocation_id` links an
 * assistant turn back to its telemetry row.
 *
 * @property int $id
 * @property int $ai_conversation_id
 * @property ?int $ai_invocation_id
 * @property string $role
 * @property string $content
 */
class AiConversationMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ai_conversation_id',
        'ai_invocation_id',
        'role',
        'content',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function getConnectionName(): ?string
    {
        return GatewaySchema::connection();
    }

    public function getTable(): string
    {
        return GatewaySchema::table('conversation_messages');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
