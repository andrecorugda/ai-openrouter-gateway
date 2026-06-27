<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Resources\AiInvocationResource\Pages;

use Andre\AiGateway\Filament\Resources\AiInvocationResource;
use Filament\Resources\Pages\ListRecords;

class ListAiInvocations extends ListRecords
{
    protected static string $resource = AiInvocationResource::class;
}
