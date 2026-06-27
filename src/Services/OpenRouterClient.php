<?php

declare(strict_types=1);

namespace Andre\AiGateway\Services;

use Andre\AiGateway\Exceptions\OpenRouterRequestException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the OpenRouter API.
 *
 * OpenRouter exposes an OpenAI-compatible /chat/completions endpoint and
 * brokers access to many providers (Anthropic, OpenAI, Google, ...) behind a
 * single API key. Swap models by changing the integration row — no code change.
 *
 * @see https://openrouter.ai/docs
 */
class OpenRouterClient
{
    private string $apiKey;

    private string $baseUrl;

    private int $timeout;

    private ?string $referer;

    private ?string $title;

    public function __construct()
    {
        $this->apiKey = (string) config('ai-gateway.openrouter.api_key', '');
        $this->baseUrl = rtrim((string) config('ai-gateway.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
        $this->timeout = (int) config('ai-gateway.openrouter.timeout', 60);
        $this->referer = config('ai-gateway.openrouter.referer') ?: null;
        $this->title = config('ai-gateway.openrouter.title') ?: null;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Send a chat-completion request. Returns the decoded JSON body with an
     * extra `_meta` envelope (generation_id, http_status) the gateway uses for
     * cost forensics without re-issuing the request.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<string,mixed>  $options  Forwarded to the API (model, max_tokens, tools, ...)
     * @return array<string,mixed>
     *
     * @throws OpenRouterRequestException
     */
    public function chat(array $messages, array $options = [], ?int $timeoutSeconds = null): array
    {
        if (! $this->isConfigured()) {
            throw new OpenRouterRequestException('OpenRouter API key is not configured');
        }

        $payload = array_merge(['messages' => $messages], $options);

        try {
            $response = $this->client($timeoutSeconds)
                ->post($this->baseUrl.'/chat/completions', $payload)
                ->throw();
        } catch (RequestException $e) {
            Log::error('OpenRouter request failed', [
                'error' => $e->getMessage(),
                'model' => $payload['model'] ?? ($payload['models'][0] ?? null),
            ]);
            $errResponse = $e->response;
            $errBody = $errResponse !== null ? $errResponse->json() : null;

            throw new OpenRouterRequestException(
                'OpenRouter request failed: '.$e->getMessage(),
                httpStatus: $errResponse->status(),
                generationId: $errResponse !== null
                    ? $this->extractGenerationId($errResponse, is_array($errBody) ? $errBody : [])
                    : null,
                body: is_array($errBody) ? $errBody : null,
                previous: $e,
            );
        } catch (\Throwable $e) {
            Log::error('OpenRouter request failed', ['error' => $e->getMessage()]);

            throw new OpenRouterRequestException('OpenRouter request failed: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new OpenRouterRequestException(
                'OpenRouter returned a non-JSON response',
                httpStatus: $response->status(),
                generationId: $this->extractGenerationId($response, []),
            );
        }

        if (isset($json['error'])) {
            $message = is_array($json['error'])
                ? ($json['error']['message'] ?? json_encode($json['error']))
                : (string) $json['error'];

            throw new OpenRouterRequestException(
                'OpenRouter error: '.$message,
                httpStatus: $response->status(),
                generationId: $this->extractGenerationId($response, $json),
                body: $json,
            );
        }

        $json['_meta'] = [
            'generation_id' => $this->extractGenerationId($response, $json),
            'http_status' => $response->status(),
        ];

        return $json;
    }

    /**
     * Extract the first message text from a chat-completion response, falling
     * back to the hidden `reasoning` field for reasoning models that emit empty
     * `content`.
     *
     * @param  array<string,mixed>  $response
     */
    public function extractText(array $response): string
    {
        $content = $response['choices'][0]['message']['content'] ?? '';
        $text = '';

        if (is_string($content)) {
            $text = $content;
        } elseif (is_array($content)) {
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                }
            }
        }

        if (trim($text) !== '') {
            return $text;
        }

        $reasoning = $response['choices'][0]['message']['reasoning'] ?? null;

        return is_string($reasoning) ? $reasoning : $text;
    }

    /**
     * @param  array<string,mixed>  $json
     */
    private function extractGenerationId(Response $response, array $json): ?string
    {
        $header = $response->header('X-Generation-Id');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $id = $json['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function client(?int $timeoutSeconds = null): PendingRequest
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($this->referer !== null) {
            $headers['HTTP-Referer'] = $this->referer;
        }
        if ($this->title !== null) {
            $headers['X-Title'] = $this->title;
        }

        $timeout = ($timeoutSeconds !== null && $timeoutSeconds > 0) ? $timeoutSeconds : $this->timeout;

        return Http::withHeaders($headers)->acceptJson()->timeout($timeout);
    }
}
