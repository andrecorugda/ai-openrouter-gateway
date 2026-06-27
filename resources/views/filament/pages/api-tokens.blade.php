<x-filament-panels::page>
    @unless ($sanctumReady)
        <x-filament::section>
            <div class="text-sm text-danger-600 dark:text-danger-400">
                Sanctum is not migrated — the <code>personal_access_tokens</code> table is missing.
                Run <code>php artisan migrate</code> after installing <code>laravel/sanctum</code> to manage API tokens.
            </div>
        </x-filament::section>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Personal access tokens carrying the <code>{{ config('ai-gateway.api.token_ability', 'ai-gateway:invoke') }}</code>
            ability. Use them as a Bearer token against the gateway's HTTP API.
        </p>

        {{ $this->table }}
    @endunless
</x-filament-panels::page>
