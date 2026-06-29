<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Resources\AiIntegrationResource\RelationManagers;

use Andre\AiGateway\Filament\Resources\AiInvocationResource;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only invocation history for an integration, shown as an "Invocations" tab
 * on the integration edit page (invocations belong to an integration, so they
 * live here instead of a top-level menu). The detail modal reuses
 * AiInvocationResource::infolist().
 */
class InvocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'invocations';

    protected static ?string $title = 'Invocations';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-chart-bar';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return AiInvocationResource::infolist($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->tooltip(fn ($record): ?string => $record->created_at?->toDayDateTimeString()),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => ['ok' => 'success', 'fallback' => 'warning', 'error' => 'danger'][$state] ?? 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('model_used')
                    ->label('Model')
                    ->searchable()
                    ->limit(28)
                    ->tooltip(fn ($record): ?string => $record->model_used),
                Tables\Columns\TextColumn::make('caller_type')
                    ->label('Caller')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('prompt_tokens')
                    ->label('Prompt')
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('completion_tokens')
                    ->label('Completion')
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('cost_usd')
                    ->label('Cost')
                    ->formatStateUsing(fn ($state): string => $state === null ? '—' : '$'.rtrim(rtrim(number_format((float) $state, 6), '0'), '.'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('latency_ms')
                    ->label('Latency')
                    ->numeric()
                    ->suffix(' ms')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['ok' => 'ok', 'fallback' => 'fallback', 'error' => 'error']),
                Tables\Filters\SelectFilter::make('caller_type')
                    ->options(['internal' => 'internal', 'api' => 'api', 'admin-test' => 'admin-test']),
                Tables\Filters\Filter::make('created_at')
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make()->modalHeading('Invocation detail'),
            ])
            ->toolbarActions([]);
    }
}
