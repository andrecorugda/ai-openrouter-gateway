<?php

declare(strict_types=1);

namespace Andre\AiGateway\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConverseRequest extends FormRequest
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
            'conversation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'message' => ['required', 'string'],
            'args' => ['sometimes', 'array'],
            'options' => ['sometimes', 'array'],
            'options.max_tokens' => ['sometimes', 'integer', 'min:1'],
            'options.temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function options(): array
    {
        $options = (array) $this->input('options', []);

        return array_intersect_key($options, array_flip(['max_tokens', 'temperature']));
    }
}
