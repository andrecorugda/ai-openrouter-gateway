<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Pages;

use Andre\AiGateway\Services\OpenRouterModelCatalog;
use Andre\AiGateway\Support\Settings;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Runtime, admin-editable gateway settings backed by {@see Settings}.
 *
 * These flip behaviour without a redeploy: the HTTP API master switch and the
 * AI prompt builder (on/off + which model it uses).
 *
 * @property Form $form
 */
class GeneralSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $view = 'ai-gateway::filament.pages.general-settings';

    protected static ?string $title = 'General settings';

    /** @var array<string,mixed> */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return config('ai-gateway.filament.navigation_group', 'AI & Automation');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-gateway.filament.navigation_sort', 50) + 1;
    }

    public function mount(): void
    {
        $all = Settings::all();

        $this->form->fill([
            'api_enabled' => (bool) ($all['api_enabled'] ?? true),
            'prompt_builder_enabled' => (bool) ($all['prompt_builder_enabled'] ?? true),
            'prompt_builder_model' => (string) ($all['prompt_builder_model'] ?? ''),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('HTTP API')
                    ->schema([
                        Toggle::make('api_enabled')
                            ->label('Enable HTTP API')
                            ->helperText('Master switch for the Sanctum-authenticated POST {prefix}/{integration}/chat endpoint.'),
                    ]),
                Section::make('AI prompt builder')
                    ->schema([
                        Toggle::make('prompt_builder_enabled')
                            ->label('Enable AI prompt builder')
                            ->helperText('Shows the "Draft with AI" helper on integration prompts.'),
                        Select::make('prompt_builder_model')
                            ->label('Prompt builder model')
                            ->options(function (): array {
                                $options = app(OpenRouterModelCatalog::class)->options();

                                // Keep the currently-saved model selectable even
                                // if it's absent from the live catalog.
                                $saved = (string) Settings::string('prompt_builder_model');
                                if ($saved !== '' && ! array_key_exists($saved, $options)) {
                                    $options[$saved] = $saved;
                                }

                                return $options;
                            })
                            ->searchable()
                            ->helperText('OpenRouter model used by the "Draft with AI" prompt builder.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Settings::set('api_enabled', (bool) ($data['api_enabled'] ?? false));
        Settings::set('prompt_builder_enabled', (bool) ($data['prompt_builder_enabled'] ?? false));
        Settings::set('prompt_builder_model', (string) ($data['prompt_builder_model'] ?? ''));

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }
}
