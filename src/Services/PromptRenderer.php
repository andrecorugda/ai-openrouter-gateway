<?php

declare(strict_types=1);

namespace Andre\AiGateway\Services;

use Andre\AiGateway\Exceptions\InvalidPromptArgsException;

/**
 * Validates and renders prompt templates with named args.
 *
 * Each version carries a `system_prompt` (the template) and a `prompt_args`
 * JSON array (the descriptors). At invocation time callers pass a
 * `[name => value]` map; this service validates the descriptors, validates the
 * caller args against them, and substitutes `{{name}}` placeholders.
 */
class PromptRenderer
{
    /** @var array<int,string> */
    public const VALID_TYPES = ['string', 'number', 'boolean', 'array', 'object', 'json'];

    private const PLACEHOLDER_RE = '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/';

    private const ARG_NAME_RE = '/^[a-z][a-z0-9_]*$/';

    private const ARG_NAME_MAX_LEN = 32;

    /**
     * @param  array<int,array<string,mixed>>  $schema
     *
     * @throws InvalidPromptArgsException
     */
    public function validateSchema(array $schema): void
    {
        $errors = [];
        $seen = [];

        foreach ($schema as $idx => $arg) {
            if (! is_array($arg)) {
                $errors['_schema_'.$idx] = ['Each arg descriptor must be an object.'];

                continue;
            }

            $name = $arg['name'] ?? null;
            $type = $arg['type'] ?? null;
            $required = $arg['required'] ?? null;

            if (! is_string($name) || $name === '') {
                $errors['_schema_'.$idx] = ['Arg descriptor is missing a name.'];

                continue;
            }

            $nameErrors = [];

            if (! preg_match(self::ARG_NAME_RE, $name)) {
                $nameErrors[] = 'Arg name "'.$name.'" must match /^[a-z][a-z0-9_]*$/.';
            }
            if (strlen($name) > self::ARG_NAME_MAX_LEN) {
                $nameErrors[] = 'Arg name "'.$name.'" exceeds '.self::ARG_NAME_MAX_LEN.' characters.';
            }
            if (isset($seen[$name])) {
                $nameErrors[] = 'Arg name "'.$name.'" is duplicated.';
            }
            $seen[$name] = true;

            if (! is_string($type) || ! in_array($type, self::VALID_TYPES, true)) {
                $nameErrors[] = 'Arg "'.$name.'" has invalid type (must be one of: '.implode(', ', self::VALID_TYPES).').';
            }
            if (! is_bool($required)) {
                $nameErrors[] = 'Arg "'.$name.'" must declare a boolean `required` flag.';
            }

            if ($nameErrors !== []) {
                $errors[$name] = $nameErrors;
            }
        }

        if ($errors !== []) {
            throw InvalidPromptArgsException::fromErrors($errors);
        }
    }

    /**
     * @param  array<string,mixed>  $args
     * @param  array<int,array<string,mixed>>  $schema
     *
     * @throws InvalidPromptArgsException
     */
    public function validate(array $args, array $schema): void
    {
        $this->validateSchema($schema);

        $errors = [];

        foreach ($schema as $arg) {
            $name = (string) $arg['name'];
            $type = (string) $arg['type'];
            $required = (bool) ($arg['required'] ?? false);
            $hasDefault = array_key_exists('default', $arg) && $arg['default'] !== null;
            $supplied = array_key_exists($name, $args);

            if (! $supplied) {
                if ($required && ! $hasDefault) {
                    $errors[$name] = ['Arg "'.$name.'" is required.'];
                }

                continue;
            }

            $typeError = $this->checkType($args[$name], $type);
            if ($typeError !== null) {
                $errors[$name] = ['Arg "'.$name.'" '.$typeError];
            }
        }

        if ($errors !== []) {
            throw InvalidPromptArgsException::fromErrors($errors);
        }
    }

    /**
     * Validate, then substitute `{{name}}` placeholders.
     *
     * @param  array<string,mixed>  $args
     * @param  array<int,array<string,mixed>>  $schema
     *
     * @throws InvalidPromptArgsException
     */
    public function render(string $template, array $args, array $schema): string
    {
        $this->validate($args, $schema);

        $values = [];
        foreach ($schema as $arg) {
            $name = (string) $arg['name'];
            $type = (string) $arg['type'];

            if (array_key_exists($name, $args)) {
                $values[$name] = $this->stringify($args[$name], $type);

                continue;
            }
            if (array_key_exists('default', $arg) && $arg['default'] !== null) {
                $values[$name] = $this->stringify($arg['default'], $type);

                continue;
            }
            $values[$name] = '';
        }

        return (string) preg_replace_callback(
            self::PLACEHOLDER_RE,
            fn (array $m): string => $values[$m[1]] ?? '',
            $template,
        );
    }

    /**
     * @return array<int,string>
     */
    public function extractPlaceholders(string $template): array
    {
        if (preg_match_all(self::PLACEHOLDER_RE, $template, $matches) === false) {
            return [];
        }

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    private function checkType(mixed $value, string $type): ?string
    {
        switch ($type) {
            case 'string':
                return is_string($value) ? null : 'must be a string.';
            case 'number':
                if (is_int($value) || is_float($value)) {
                    return null;
                }

                return is_string($value) && is_numeric($value) ? null : 'must be numeric.';
            case 'boolean':
                return is_bool($value) ? null : 'must be a boolean.';
            case 'array':
                if (! is_array($value)) {
                    return 'must be a list.';
                }
                if ($value === []) {
                    return null;
                }

                return array_is_list($value) ? null : 'must be a list (no string keys).';
            case 'object':
                if (! is_array($value) || (array_is_list($value) && $value !== [])) {
                    return 'must be an object (associative array).';
                }

                return null;
            case 'json':
                if (! is_string($value)) {
                    return 'must be a JSON string.';
                }
                json_decode($value, true);

                return json_last_error() !== JSON_ERROR_NONE ? 'must be a valid JSON string.' : null;
            default:
                return 'has unknown type "'.$type.'".';
        }
    }

    private function stringify(mixed $value, string $type): string
    {
        if ($type === 'json' && is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $json !== false ? $json : '';
        }

        return (string) $value;
    }
}
