<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Resources\AiIntegrationResource\Pages;

use Andre\AiGateway\Filament\Resources\AiIntegrationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAiIntegrations extends ListRecords
{
    protected static string $resource = AiIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
