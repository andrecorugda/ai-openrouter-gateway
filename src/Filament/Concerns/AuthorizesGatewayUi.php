<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Enforces the config('ai-gateway.filament.authorize') gate for a Filament
 * resource or page. The value is a Gate ability name, a callable, or null
 * (open to any panel user), as documented in config/ai-gateway.php.
 */
trait AuthorizesGatewayUi
{
    public static function canAccess(): bool
    {
        return static::passesGatewayGate();
    }

    protected static function passesGatewayGate(): bool
    {
        $gate = config('ai-gateway.filament.authorize');

        if ($gate === null) {
            return true;
        }

        if (is_string($gate)) {
            return Gate::allows($gate);
        }

        if (is_callable($gate)) {
            return (bool) $gate(Auth::user());
        }

        return false;
    }
}
