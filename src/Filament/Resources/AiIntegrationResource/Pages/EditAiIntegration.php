<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Resources\AiIntegrationResource\Pages;

use Andre\AiGateway\Filament\Resources\AiIntegrationResource;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Services\AiIntegrationService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAiIntegration extends EditRecord
{
    protected static string $resource = AiIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Reuse the resource's Test action from the edit header.
            AiIntegrationResource::testAction(),
            Actions\DeleteAction::make(),
        ];
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
        $data['models'] = is_array($version?->models) ? $version->models : [];
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
