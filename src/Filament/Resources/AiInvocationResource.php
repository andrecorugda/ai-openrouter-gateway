<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Resources;

use Andre\AiGateway\Models\AiInvocation;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only telemetry browser for `ai_invocations` — one row per gateway call
 * (success and failure). Filterable by status / caller / integration / date,
 * with cost + token sum summaries and a per-row detail modal.
 */
class AiInvocationResource extends Resource
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-gateway.models.invocation', AiInvocation::class);
    }

    public static function getModelLabel(): string
    {
        return 'invocation';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Invocations';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-gateway.filament.navigation_group', 'AI & Automation');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-gateway.filament.navigation_sort', 50) + 3;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return array<string,string> */
    private static function statusColors(): array
    {
        return ['ok' => 'success', 'fallback' => 'warning', 'error' => 'danger'];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->tooltip(fn ($record): ?string => $record->created_at?->toDayDateTimeString()),
                Tables\Columns\TextColumn::make('integration_slug_snapshot')
                    ->label('Integration')
                    ->searchable()
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => static::statusColors()[$state] ?? 'gray')
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
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Σ prompt'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('completion_tokens')
                    ->label('Completion')
                    ->numeric()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Σ completion'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('cost_usd')
                    ->label('Cost')
                    ->money('usd', divideBy: 1)
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Σ cost')->money('usd')),
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
                Tables\Filters\SelectFilter::make('integration_slug_snapshot')
                    ->label('Integration')
                    ->options(fn (): array => static::getModel()::query()
                        ->whereNotNull('integration_slug_snapshot')
                        ->distinct()
                        ->orderBy('integration_slug_snapshot')
                        ->pluck('integration_slug_snapshot', 'integration_slug_snapshot')
                        ->all()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->modalHeading('Invocation detail'),
            ])
            ->poll('30s');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Call')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('integration_slug_snapshot')->label('Integration'),
                    Infolists\Components\TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => static::statusColors()[$state] ?? 'gray'),
                    Infolists\Components\TextEntry::make('caller_type')->label('Caller'),
                    Infolists\Components\TextEntry::make('caller_id')->label('Caller id')->placeholder('—'),
                    Infolists\Components\TextEntry::make('model_requested')->label('Requested')->placeholder('—'),
                    Infolists\Components\TextEntry::make('model_used')->label('Used')->placeholder('—'),
                    Infolists\Components\TextEntry::make('created_at')->dateTime(),
                ]),
            Infolists\Components\Section::make('Usage & cost')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('prompt_tokens')->numeric()->placeholder('—'),
                    Infolists\Components\TextEntry::make('completion_tokens')->numeric()->placeholder('—'),
                    Infolists\Components\TextEntry::make('cached_tokens')->numeric()->placeholder('—'),
                    Infolists\Components\TextEntry::make('cost_usd')->money('usd', divideBy: 1)->placeholder('—'),
                    Infolists\Components\TextEntry::make('latency_ms')->suffix(' ms')->placeholder('—'),
                    Infolists\Components\TextEntry::make('citation_count')->label('Citations')->placeholder('—'),
                    Infolists\Components\TextEntry::make('openrouter_generation_id')
                        ->label('OpenRouter generation')
                        ->placeholder('—')
                        ->url(fn ($record): ?string => $record->openrouter_generation_id
                            ? 'https://openrouter.ai/activity/'.$record->openrouter_generation_id
                            : null, true)
                        ->color('primary'),
                ]),
            Infolists\Components\Section::make('Error')
                ->visible(fn ($record): bool => $record->status === 'error')
                ->schema([
                    Infolists\Components\TextEntry::make('error_class')->placeholder('—'),
                    Infolists\Components\TextEntry::make('error_message')->placeholder('—')->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => AiInvocationResource\Pages\ListAiInvocations::route('/'),
        ];
    }
}
