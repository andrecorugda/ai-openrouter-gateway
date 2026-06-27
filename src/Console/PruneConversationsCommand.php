<?php

declare(strict_types=1);

namespace Andre\AiGateway\Console;

use Andre\AiGateway\Models\AiConversation;
use Illuminate\Console\Command;

/**
 * Soft-deletes conversation threads past their `expires_at`. Schedule it daily
 * (e.g. `$schedule->command('ai-gateway:prune-conversations')->daily()`).
 */
class PruneConversationsCommand extends Command
{
    protected $signature = 'ai-gateway:prune-conversations';

    protected $description = 'Soft-delete expired AI Gateway conversation threads';

    public function handle(): int
    {
        /** @var class-string<AiConversation> $model */
        $model = config('ai-gateway.models.conversation', AiConversation::class);

        $count = $model::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Pruned {$count} expired conversation(s).");

        return self::SUCCESS;
    }
}
