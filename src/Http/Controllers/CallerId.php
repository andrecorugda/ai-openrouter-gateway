<?php

declare(strict_types=1);

namespace Andre\AiGateway\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Resolves a stable caller identifier for telemetry + conversation ownership:
 * the Sanctum token id when present, else the user id.
 */
final class CallerId
{
    public static function resolve(Request $request): ?string
    {
        $user = $request->user();
        $token = ($user !== null && method_exists($user, 'currentAccessToken')) ? $user->currentAccessToken() : null;

        if ($token !== null && isset($token->id)) {
            return 'token:'.$token->id;
        }

        return $user?->getAuthIdentifier() !== null ? 'user:'.$user->getAuthIdentifier() : null;
    }
}
