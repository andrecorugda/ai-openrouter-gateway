<?php

declare(strict_types=1);

namespace Andre\AiGateway\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Thrown when caller-supplied args fail validation against an integration's
 * declared `prompt_args` schema, or when the schema itself is malformed.
 *
 * Extends Laravel's ValidationException so framework error handling maps it to
 * a 422 with a structured `errors` bag automatically.
 */
class InvalidPromptArgsException extends ValidationException
{
    /**
     * Build from a [field => string[]] error bag.
     *
     * @param  array<string,array<int,string>>  $errors
     */
    public static function fromErrors(array $errors): self
    {
        $validator = Validator::make([], []);

        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $validator->errors()->add($field, $message);
            }
        }

        return new self($validator);
    }
}
