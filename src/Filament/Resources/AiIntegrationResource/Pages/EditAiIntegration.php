<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Resources\AiIntegrationResource\Pages;

use Andre\AiGateway\Filament\Resources\AiIntegrationResource;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Models\AiIntegrationVersion;
use Andre\AiGateway\Services\AiIntegrationService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAiIntegration extends EditRecord
{
    protected static string $resource = AiIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A page-header (Filament\Actions\Action) Test action that reuses the
            // resource's shared form schema + run logic. In Filament v5 all
            // actions are unified into Filament\Actions\Action.
            Actions\Action::make('test')
                ->label('Test')
                ->icon('heroicon-m-play')
                ->color('gray')
                ->disabled(fn (): bool => $this->getRecord()->activeVersion === null)
                ->modalHeading(fn (): string => 'Test "'.$this->getRecord()->name.'"')
                ->modalSubmitActionLabel('Run')
                ->form(fn (): array => AiIntegrationResource::testFormSchema($this->getRecord()))
                ->action(fn (array $data) => AiIntegrationResource::runTest($this->getRecord(), $data)),

            // Versions: pick a past version and load its editable surface into
            // the form (optionally activating it). Saving then mints it as the
            // new active version (rollback-by-clone).
            Actions\Action::make('versions')
                ->label('Versions')
                ->icon('heroicon-m-clock')
                ->color('gray')
                ->modalHeading('Versions')
                ->modalSubmitActionLabel('Load into form')
                ->fillForm(fn (): array => ['version_id' => $this->getRecord()->activeVersion?->id])
                ->form([
                    Forms\Components\Select::make('version_id')
                        ->label('Version')
                        ->native(false)
                        ->required()
                        ->options(fn (): array => $this->getRecord()->versions
                            ->mapWithKeys(fn ($v): array => [$v->id => sprintf(
                                'v%d — %s%s',
                                $v->version_number,
                                $v->created_at?->toDayDateTimeString() ?? 'unknown date',
                                $v->is_active ? '  (active)' : '',
                            )])
                            ->all()),
                    Forms\Components\Toggle::make('activate')
                        ->label('Also activate this version now')
                        ->helperText('Otherwise it just loads into the form; Save mints a new active version.')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    $version = $this->getRecord()->versions()->find($data['version_id'] ?? null);
                    if ($version === null) {
                        Notification::make()->danger()->title('Version not found')->send();

                        return;
                    }

                    $this->loadVersionIntoForm($version);

                    if (! empty($data['activate'])) {
                        app(AiIntegrationService::class)->activate($version);
                        Notification::make()->success()
                            ->title('Activated v'.$version->version_number)
                            ->body('Loaded into the form as the active version.')
                            ->send();
                    } else {
                        Notification::make()->success()
                            ->title('Loaded v'.$version->version_number.' into the form')
                            ->body('Save to mint it as the new active version.')
                            ->send();
                    }
                }),

            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Fill the edit form with a specific version's editable surface, preserving
     * the registry fields already in the form.
     */
    protected function loadVersionIntoForm(AiIntegrationVersion $version): void
    {
        $models = is_array($version->models) ? array_values($version->models) : [];

        $state = $this->data ?? [];
        $state['primary_model'] = $models[0] ?? null;
        $state['fallback_models'] = array_slice($models, 1);
        $state['system_prompt'] = (string) ($version->system_prompt ?? '');
        $state['system_prompt_cacheable'] = (bool) $version->system_prompt_cacheable;
        $state['default_params'] = is_array($version->default_params) ? $version->default_params : [];
        $state['prompt_args'] = is_array($version->prompt_args) ? $version->prompt_args : [];
        $state = array_merge(
            $state,
            AiIntegrationResource::flattenServerTools(is_array($version->server_tools) ? $version->server_tools : null),
        );

        $this->form->fill($state);
    }

    /**
     * Merge the active version's editable surface into the form data, and
     * flatten server_tools into the form's toggle fields.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var AiIntegration $record */
        $record = $this->record;
        $version = $record->activeVersion;

        $data['system_prompt'] = $version?->system_prompt ?? '';
        $data['system_prompt_cacheable'] = (bool) ($version?->system_prompt_cacheable ?? true);

        // Split the stored ordered models list back into the two form-only
        // Selects: primary (index 0) + fallbacks (the rest).
        $models = is_array($version?->models) ? array_values($version->models) : [];
        $data['primary_model'] = $models[0] ?? null;
        $data['fallback_models'] = array_slice($models, 1);

        $data['default_params'] = is_array($version?->default_params) ? $version->default_params : [];
        $data['prompt_args'] = is_array($version?->prompt_args) ? $version->prompt_args : [];

        $data = array_merge(
            $data,
            AiIntegrationResource::flattenServerTools(is_array($version?->server_tools) ? $version->server_tools : null),
        );

        return $data;
    }

    /**
     * Update the registry columns on the model, then mint + activate a new
     * version from the version-only fields.
     *
     * @param  AiIntegration  $record
     * @param  array<string,mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $registry = AiIntegrationResource::onlyRegistryAttributes($data);
        $registry['updated_by'] = auth()->id();

        $record->update($registry);

        app(AiIntegrationService::class)->saveVersion(
            $record,
            AiIntegrationResource::extractVersionAttributes($data),
            activate: true,
            userId: auth()->id(),
        );

        // Refresh so the freshly activated version is reflected.
        $record->refresh();

        return $record;
    }
}
