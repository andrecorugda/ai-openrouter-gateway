<x-filament-panels::page>
    {{-- Inline width/height so sizing doesn't depend on the host app's compiled
         Tailwind (package-view utility classes may not be in its CSS build). --}}
    <iframe
        src="{{ $this->getDocsUrl() }}"
        title="AI Gateway API documentation"
        style="width: 100%; height: 82vh; min-height: 32rem; border: 1px solid rgb(229 231 235); border-radius: 0.75rem; background: #fff;"
        loading="lazy"
    ></iframe>
</x-filament-panels::page>
