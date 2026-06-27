<x-filament-panels::page>
    <div class="w-full" style="height: calc(100vh - 12rem); min-height: 32rem;">
        <iframe
            src="{{ $this->getDocsUrl() }}"
            title="AI Gateway API documentation"
            class="w-full h-full rounded-xl border border-gray-200 dark:border-gray-700 bg-white"
            loading="lazy"
        ></iframe>
    </div>
</x-filament-panels::page>
