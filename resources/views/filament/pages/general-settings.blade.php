<x-filament-panels::page>
    <x-filament-panels::form wire:submit="save">
        {{ $this->form }}

        <div class="flex justify-start">
            <x-filament::button type="submit">
                Save
            </x-filament::button>
        </div>
    </x-filament-panels::form>
</x-filament-panels::page>
