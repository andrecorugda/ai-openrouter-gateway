<?php

declare(strict_types=1);

namespace Andre\AiGateway\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvokeAiIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'args' => ['sometimes', 'array'],
            'messages' => ['sometimes', 'array'],
            'messages.*.role' => ['required_with:messages', 'string', 'in:system,user,assistant'],
            'messages.*.content' => ['required_with:messages'],
            'options' => ['sometimes', 'array'],
            'options.max_tokens' => ['sometimes', 'integer', 'min:1'],
            'options.temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
        ];
    }

    /**
     * Allow-listed per-call options forwarded to the gateway.
     *
     * @return array<string,mixed>
     */
    public function options(): array
    {
        $options = (array) $this->input('options', []);

        return array_intersect_key($options, array_flip(['max_tokens', 'temperature']));
    }
}
