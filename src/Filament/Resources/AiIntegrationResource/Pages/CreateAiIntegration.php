<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Resources\AiIntegrationResource\Pages;

use Andre\AiGateway\Filament\Resources\AiIntegrationResource;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Services\AiIntegrationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAiIntegration extends CreateRecord
{
    protected static string $resource = AiIntegrationResource::class;

    /**
     * Create the registry row with ONLY its own columns, then mint + activate
     * the first version from the version-only fields.
     *
     * @param  array<string,mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $registry = AiIntegrationResource::onlyRegistryAttributes($data);
        $registry['created_by'] = auth()->id();

        /** @var class-string<AiIntegration> $modelClass */
        $modelClass = static::getResource()::getModel();

        /** @var AiIntegration $integration */
        $integration = $modelClass::create($registry);

        app(AiIntegrationService::class)->saveVersion(
            $integration,
            AiIntegrationResource::extractVersionAttributes($data),
            activate: true,
            userId: auth()->id(),
        );

        return $integration;
    }
}
