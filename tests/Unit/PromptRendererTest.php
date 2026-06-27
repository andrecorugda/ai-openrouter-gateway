<?php

declare(strict_types=1);

use Andre\AiGateway\Exceptions\InvalidPromptArgsException;
use Andre\AiGateway\Services\PromptRenderer;

beforeEach(function (): void {
    $this->renderer = new PromptRenderer;
});

// ---------------------------------------------------------------------------
// validateSchema
// ---------------------------------------------------------------------------

it('accepts a well-formed schema', function (): void {
    $schema = [
        ['name' => 'company', 'type' => 'string', 'required' => true],
        ['name' => 'count', 'type' => 'number', 'required' => false],
    ];

    $this->renderer->validateSchema($schema);
})->throwsNoExceptions();

it('rejects an arg name that breaks the naming rule', function (): void {
    $this->renderer->validateSchema([
        ['name' => 'Bad-Name', 'type' => 'string', 'required' => true],
    ]);
})->throws(InvalidPromptArgsException::class);

it('rejects an unknown arg type', function (): void {
    $this->renderer->validateSchema([
        ['name' => 'thing', 'type' => 'datetime', 'required' => true],
    ]);
})->throws(InvalidPromptArgsException::class);

it('rejects a non-boolean required flag', function (): void {
    $this->renderer->validateSchema([
        ['name' => 'thing', 'type' => 'string', 'required' => 'yes'],
    ]);
})->throws(InvalidPromptArgsException::class);

it('rejects duplicate arg names', function (): void {
    try {
        $this->renderer->validateSchema([
            ['name' => 'dup', 'type' => 'string', 'required' => true],
            ['name' => 'dup', 'type' => 'string', 'required' => false],
        ]);
        $this->fail('Expected InvalidPromptArgsException for duplicate names.');
    } catch (InvalidPromptArgsException $e) {
        $messages = collect($e->errors()['dup'] ?? [])->implode(' ');
        expect($messages)->toContain('duplicated');
    }
});

// ---------------------------------------------------------------------------
// validate
// ---------------------------------------------------------------------------

it('throws when a required arg with no default is missing', function (): void {
    $schema = [['name' => 'name', 'type' => 'string', 'required' => true]];

    try {
        $this->renderer->validate([], $schema);
        $this->fail('Expected InvalidPromptArgsException for missing required arg.');
    } catch (InvalidPromptArgsException $e) {
        expect($e->errors())->toHaveKey('name');
    }
});

it('does not throw when a missing required arg has a default', function (): void {
    $schema = [['name' => 'name', 'type' => 'string', 'required' => true, 'default' => 'Anon']];

    $this->renderer->validate([], $schema);
})->throwsNoExceptions();

it('throws on a type mismatch', function (): void {
    $schema = [['name' => 'count', 'type' => 'number', 'required' => true]];

    try {
        $this->renderer->validate(['count' => 'not-a-number'], $schema);
        $this->fail('Expected InvalidPromptArgsException for a type mismatch.');
    } catch (InvalidPromptArgsException $e) {
        expect($e->errors())->toHaveKey('count');
    }
});

it('accepts a numeric string for a number arg', function (): void {
    $schema = [['name' => 'count', 'type' => 'number', 'required' => true]];

    $this->renderer->validate(['count' => '42'], $schema);
})->throwsNoExceptions();

it('rejects an associative array supplied for an array (list) arg', function (): void {
    $schema = [['name' => 'tags', 'type' => 'array', 'required' => true]];

    expect(fn () => $this->renderer->validate(['tags' => ['a' => 1]], $schema))
        ->toThrow(InvalidPromptArgsException::class);
});

// ---------------------------------------------------------------------------
// render — substitution, defaults, stringification
// ---------------------------------------------------------------------------

it('substitutes {{name}} placeholders with supplied values', function (): void {
    $schema = [['name' => 'name', 'type' => 'string', 'required' => true]];

    $out = $this->renderer->render('Hello {{name}}!', ['name' => 'Ada'], $schema);

    expect($out)->toBe('Hello Ada!');
});

it('applies a default when the arg is not supplied', function (): void {
    $schema = [['name' => 'name', 'type' => 'string', 'required' => false, 'default' => 'Stranger']];

    $out = $this->renderer->render('Hi {{name}}', [], $schema);

    expect($out)->toBe('Hi Stranger');
});

it('stringifies booleans as "true" / "false"', function (): void {
    $schema = [['name' => 'flag', 'type' => 'boolean', 'required' => true]];

    expect($this->renderer->render('{{flag}}', ['flag' => true], $schema))->toBe('true');
    expect($this->renderer->render('{{flag}}', ['flag' => false], $schema))->toBe('false');
});

it('stringifies arrays as JSON', function (): void {
    $schema = [['name' => 'tags', 'type' => 'array', 'required' => true]];

    $out = $this->renderer->render('{{tags}}', ['tags' => ['a', 'b']], $schema);

    expect(json_decode($out, true))->toBe(['a', 'b']);
});

it('renders an unmatched placeholder as an empty string', function (): void {
    // No schema entry for {{ghost}} → the value map has no key → empty.
    $schema = [['name' => 'present', 'type' => 'string', 'required' => true]];

    $out = $this->renderer->render('[{{ghost}}][{{present}}]', ['present' => 'x'], $schema);

    expect($out)->toBe('[][x]');
});

// ---------------------------------------------------------------------------
// extractPlaceholders
// ---------------------------------------------------------------------------

it('extracts unique, sorted placeholder names', function (): void {
    $names = $this->renderer->extractPlaceholders('{{zebra}} {{alpha}} {{zebra}} text {{ mid }}');

    expect($names)->toBe(['alpha', 'mid', 'zebra']);
});

it('returns an empty array when there are no placeholders', function (): void {
    expect($this->renderer->extractPlaceholders('no placeholders here'))->toBe([]);
});
