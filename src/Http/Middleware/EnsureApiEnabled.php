<?php

declare(strict_types=1);

namespace Andre\AiGateway\Http\Middleware;

use Andre\AiGateway\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the whole HTTP API behind the runtime `api_enabled` setting (toggled
 * from the admin General settings page). When off, the routes behave as if
 * they don't exist.
 */
class EnsureApiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Settings::bool('api_enabled')) {
            return response()->json(['message' => 'AI gateway API is disabled'], 404);
        }

        return $next($request);
    }
}
