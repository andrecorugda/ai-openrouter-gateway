<?php

declare(strict_types=1);

namespace Andre\AiGateway\Models;

use Andre\AiGateway\Support\Schema as GatewaySchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A multi-turn conversation thread for an integration flagged
 * `is_conversational`. Identified externally by an opaque `uuid`; expires after
 * the integration's `conversation_ttl_minutes` of inactivity.
 *
 * @property int $id
 * @property string $uuid
 * @property int $ai_integration_id
 * @property string $caller_type
 * @property ?string $caller_id
 * @property string $status
 * @property ?Carbon $expires_at
 * @property int $message_count
 */
class AiConversation extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'uuid',
        'ai_integration_id',
        'caller_type',
        'caller_id',
        'status',
        'metadata',
        'last_activity_at',
        'expires_at',
        'message_count',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'message_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $conversation): void {
            if (empty($conversation->uuid)) {
                $conversation->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function getConnectionName(): ?string
    {
        return GatewaySchema::connection();
    }

    public function getTable(): string
    {
        return GatewaySchema::table('conversations');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(AiIntegration::class, 'ai_integration_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiConversationMessage::class, 'ai_conversation_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
