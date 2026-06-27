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
    <div
        x-data="{
            showVars: true,
            insert(name) {
                const ta = this.$refs.editor;
                if (! ta) {
                    return;
                }
                const token = @js($tokenOpen) + name + @js($tokenClose);
                const start = ta.selectionStart ?? ta.value.length;
                const end = ta.selectionEnd ?? ta.value.length;
                const before = ta.value.slice(0, start);
                const after = ta.value.slice(end);
                ta.value = before + token + after;
                // Notify Livewire of the change so the entangled state syncs.
                ta.dispatchEvent(new Event('input', { bubbles: true }));
                // Restore caret just past the inserted token.
                const caret = start + token.length;
                ta.focus();
                ta.setSelectionRange(caret, caret);
            },
        }"
        class="flex flex-col gap-3 sm:flex-row sm:items-start"
    >
        {{-- Editor --}}
        <div class="min-w-0 flex-1">
            <textarea
                x-ref="editor"
                style="min-height: 16rem;"
                {{
                    $getExtraInputAttributeBag()
                        ->merge([
                            'disabled' => $isDisabled(),
                            'id' => $getId(),
                            'readonly' => $isReadOnly(),
                            'required' => $isRequired(),
                            $applyStateBindingModifiers('wire:model') => $statePath,
                        ], escape: false)
                        ->class([
                            'fi-input block w-full resize-y rounded-lg border-none bg-white px-3 py-2 font-mono text-sm text-gray-950 shadow-sm outline-none ring-1 ring-gray-950/10 transition duration-75 placeholder:text-gray-400 focus:ring-2 focus:ring-primary-600 disabled:text-gray-500 dark:bg-white/5 dark:text-white dark:ring-white/20 dark:placeholder:text-gray-500 dark:focus:ring-primary-500 dark:disabled:text-gray-400',
                        ])
                }}
            ></textarea>
        </div>

        {{-- Variables side panel --}}
        <div class="sm:sticky sm:top-4 sm:w-56 sm:flex-shrink-0">
            <button
                type="button"
                x-on:click="showVars = ! showVars"
                class="mb-2 inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
            >
                <span x-text="showVars ? 'Hide variables' : 'Show variables'"></span>
            </button>

            <div x-show="showVars" x-cloak class="space-y-1.5">
                @if (count($variables) > 0)
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Click to insert at the cursor.
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($variables as $variable)
                            <button
                                type="button"
                                x-on:click="insert(@js($variable))"
                                class="inline-flex items-center rounded-md bg-primary-50 px-2 py-1 font-mono text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20 transition hover:bg-primary-100 dark:bg-primary-400/10 dark:text-primary-300 dark:ring-primary-400/30 dark:hover:bg-primary-400/20"
                            >
                                {{ $variable }}
                            </button>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        No variables declared yet. Add them in the Variables section.
                    </p>
                @endif
            </div>
        </div>
    </div>
</x-dynamic-component>
