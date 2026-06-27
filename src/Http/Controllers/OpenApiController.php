<?php

declare(strict_types=1);

namespace Andre\AiGateway\Http\Controllers;

use Andre\AiGateway\Services\OpenApiGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Serves the live OpenAPI document built from the registered integrations, and
 * an interactive "try it" docs page (Scalar by default) that renders it.
 */
class OpenApiController
{
    public function spec(OpenApiGenerator $generator): JsonResponse
    {
        return response()->json($generator->generate());
    }

    public function docs(): View
    {
        // Scalar reads its options from a JSON `data-configuration` attribute;
        // encode it attribute-safe so it survives either quote style.
        $configuration = json_encode(
            ['darkMode' => (bool) config('ai-gateway.api.docs.dark_mode', true)],
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP,
        );

        return view('ai-gateway::docs', [
            'specUrl' => route('ai-gateway.openapi'),
            'title' => (string) config('app.name', 'Laravel').' — AI Gateway API',
            'scriptSrc' => (string) config('ai-gateway.api.docs.script_src', 'https://cdn.jsdelivr.net/npm/@scalar/api-reference'),
            'configuration' => $configuration !== false ? $configuration : '{}',
        ]);
    }
}
