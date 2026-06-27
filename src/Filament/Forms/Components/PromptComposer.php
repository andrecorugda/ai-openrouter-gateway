<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Concerns\CanBeReadOnly;
use Filament\Forms\Components\Concerns\HasExtraInputAttributes;
use Filament\Forms\Components\Field;

/**
 * Interactive system-prompt editor with click-to-insert variables.
 *
 * Renders a monospace textarea entangled to the field state alongside a
 * toggleable side panel listing the declared prompt variables. Clicking a
 * variable splices `{{name}}` into the textarea at the caret — mirroring
 * gvnext's PromptEditor "insert variable" affordance.
 *
 * The declared variable names are supplied via {@see variables()} as a closure
 * so the panel stays in sync with the live Variables repeater.
 */
class PromptComposer extends Field
{
    use CanBeReadOnly;
    use HasExtraInputAttributes;

    protected string $view = 'ai-gateway::filament.forms.prompt-composer';

    /**
     * Closure (or array) yielding the declared variable names shown in the
     * insert panel.
     *
     * @var Closure|array<int,string>|null
     */
    protected Closure|array|null $variables = null;

    /**
     * @param  Closure|array<int,string>  $variables
     */
    public function variables(Closure|array $variables): static
    {
        $this->variables = $variables;

        return $this;
    }

    /**
     * Resolve the declared variable names for the view.
     *
     * @return array<int,string>
     */
    public function getVariables(): array
    {
        $resolved = $this->evaluate($this->variables);

        if (! is_array($resolved)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v): string => (string) $v, $resolved),
            static fn (string $v): bool => $v !== '',
        ));
    }
}
