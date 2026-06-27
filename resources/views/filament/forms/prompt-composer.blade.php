@php
    $variables = $getVariables();
    $statePath = $getStatePath();
    // Built in PHP so the literal {{ }} delimiters never hit the Blade parser.
    $tokenOpen = '{'.'{';
    $tokenClose = '}'.'}';
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    {{-- Inline styles for layout: a package view can't rely on the host app's
         Tailwind build including arbitrary utility classes (it doesn't, under
         Filament 4/5). Filament's own .fi-input class still styles the textarea. --}}
    <div
        x-data="{
            showVars: true,
            insert(name) {
                const ta = this.$refs.editor;
                if (! ta) { return; }
                const token = @js($tokenOpen) + name + @js($tokenClose);
                const start = ta.selectionStart ?? ta.value.length;
                const end = ta.selectionEnd ?? ta.value.length;
                ta.value = ta.value.slice(0, start) + token + ta.value.slice(end);
                ta.dispatchEvent(new Event('input', { bubbles: true }));
                const caret = start + token.length;
                ta.focus();
                ta.setSelectionRange(caret, caret);
            },
        }"
        style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-start;"
    >
        {{-- Editor --}}
        <div style="flex: 1 1 22rem; min-width: 0;">
            <textarea
                x-ref="editor"
                style="min-height: 16rem; width: 100%; resize: vertical; border-radius: 0.5rem; padding: 0.5rem 0.75rem; font-family: ui-monospace, monospace; font-size: 0.875rem; border: 1px solid rgb(0 0 0 / 0.1); background: #fff; color: rgb(17 24 39); outline: none;"
                {{
                    $getExtraInputAttributeBag()
                        ->merge([
                            'disabled' => $isDisabled(),
                            'id' => $getId(),
                            'readonly' => $isReadOnly(),
                            'required' => $isRequired(),
                            $applyStateBindingModifiers('wire:model') => $statePath,
                        ], escape: false)
                        ->class(['fi-input'])
                }}
            ></textarea>
        </div>

        {{-- Variables side panel --}}
        <div style="flex: 0 0 13rem; position: sticky; top: 1rem;">
            <button
                type="button"
                x-on:click="showVars = ! showVars"
                style="margin-bottom: 0.5rem; font-size: 0.75rem; font-weight: 600; color: rgb(79 70 229); background: none; border: 0; cursor: pointer; padding: 0;"
            >
                <span x-text="showVars ? 'Hide variables' : 'Show variables'"></span>
            </button>

            <div x-show="showVars" x-cloak style="display: flex; flex-direction: column; gap: 0.375rem;">
                @if (count($variables) > 0)
                    <p style="font-size: 0.75rem; color: rgb(107 114 128); margin: 0;">Click to insert at the cursor.</p>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.375rem;">
                        @foreach ($variables as $variable)
                            <button
                                type="button"
                                x-on:click="insert(@js($variable))"
                                style="display: inline-flex; align-items: center; padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-family: ui-monospace, monospace; font-size: 0.75rem; font-weight: 500; background: rgb(99 102 241 / 0.1); color: rgb(67 56 202); border: 1px solid rgb(99 102 241 / 0.25); cursor: pointer;"
                            >{{ $variable }}</button>
                        @endforeach
                    </div>
                @else
                    <p style="font-size: 0.75rem; color: rgb(107 114 128); margin: 0;">No variables declared yet — add them with "Manage variables".</p>
                @endif
            </div>
        </div>
    </div>
</x-dynamic-component>
