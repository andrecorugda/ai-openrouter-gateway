<?php

declare(strict_types=1);

namespace Andre\AiGateway\Services;

/**
 * Result of one AiGateway invocation. Wraps the assistant text + the
 * operational metadata (model, usage, cost, latency, generation id) callers
 * and admin tooling need without re-decoding the raw provider response.
 */
readonly class AiResult
{
    /**
     * @param  array<string,mixed>  $messages  Full assistant message (choices[0].message).
     * @param  array<int,array{url:string,title:?string}>  $citations  Normalized web-search sources.
     * @param  array<string,int|null>  $usage  { prompt_tokens, completion_tokens, cached_tokens, total_tokens }
     * @param  array<int,array<string,mixed>>  $attempts  Trace — [{model, status, latency_ms}].
     */
    public function __construct(
        public string $text,
        public array $messages,
        public array $citations,
        public array $usage,
        public string $model_used,
        public string $provider_used,
        public ?string $finish_reason,
        public ?float $cost_usd,
        public ?int $latency_ms,
        public array $attempts,
        public ?string $generation_id,
        public ?int $invocation_id = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'messages' => $this->messages,
            'citations' => $this->citations,
            'usage' => $this->usage,
            'model_used' => $this->model_used,
            'provider_used' => $this->provider_used,
            'finish_reason' => $this->finish_reason,
            'cost_usd' => $this->cost_usd,
            'latency_ms' => $this->latency_ms,
            'attempts' => $this->attempts,
            'generation_id' => $this->generation_id,
            'invocation_id' => $this->invocation_id,
        ];
    }
}
