<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Concerns;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Enforces config('ai-gateway.filament.authorize') on a gateway resource or
 * page, on top of Filament's own check (a resource's policy, a page's default).
 *
 * The value is, as documented in config/ai-gateway.php:
 *  - null or ''           → open to anyone who can access the panel;
 *  - a Gate ability name  → Gate::forUser($user)->allows($ability);
 *  - an invokable class   → resolved from the container, called with the user
 *                           (the form that survives `php artisan config:cache`);
 *  - a closure / callable → called with the user.
 *
 * Anything else denies: a gate that cannot be evaluated fails closed.
 */
trait AuthorizesGatewayUi
{
    public static function canAccess(): bool
    {
        return static::passesGatewayGate() && parent::canAccess();
    }

    public static function passesGatewayGate(): bool
    {
        $gate = config('ai-gateway.filament.authorize');

        if ($gate === null || $gate === '') {
            return true;
        }

        $user = static::gatewayUser();

        if ($gate instanceof Closure) {
            return (bool) $gate($user);
        }

        if (is_string($gate)) {
            if (class_exists($gate)) {
                $invokable = app($gate);

                return is_callable($invokable) && (bool) $invokable($user);
            }

            return Gate::forUser($user)->allows($gate);
        }

        if (is_callable($gate)) {
            return (bool) $gate($user);
        }

        return false;
    }

    /** The panel's own guard, so a panel on a non-default guard is judged by its user. */
    protected static function gatewayUser(): ?Authenticatable
    {
        return Filament::getCurrentPanel()?->auth()->user() ?? Auth::user();
    }
}
