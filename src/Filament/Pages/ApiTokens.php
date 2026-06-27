<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Manage the authenticated user's Sanctum personal access tokens that carry
 * the gateway's invoke ability ({@see config('ai-gateway.api.token_ability')}).
 *
 * Plain-text tokens are only available at creation time, so the create action
 * surfaces the value once in a persistent notification.
 */
class ApiTokens extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static string $view = 'ai-gateway::filament.pages.api-tokens';

    protected static ?string $title = 'API tokens';

    public static function getNavigationGroup(): ?string
    {
        return config('ai-gateway.filament.navigation_group', 'AI & Automation');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('ai-gateway.filament.navigation_sort', 50) + 2;
    }

    /** The Sanctum ability minted tokens must carry to reach the invoke endpoint. */
    protected static function ability(): string
    {
        return (string) config('ai-gateway.api.token_ability', 'ai-gateway:invoke');
    }

    /** True when the Sanctum tokens table exists / is reachable. */
    protected function sanctumReady(): bool
    {
        try {
            // hasTable() returns false (it does NOT throw) when the table is
            // missing, so we must return its result — not just "didn't throw".
            return (new PersonalAccessToken)->getConnection()
                ->getSchemaBuilder()
                ->hasTable((new PersonalAccessToken)->getTable());
        } catch (Throwable) {
            return false;
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->tokensQuery())
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('abilities')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state),
                TextColumn::make('last_used_at')
                    ->dateTime()
                    ->placeholder('never'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                TableAction::make('create')
                    ->label('Create token')
                    ->icon('heroicon-m-plus')
                    ->form([
                        TextInput::make('name')
                            ->label('Token name')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('e.g. n8n production'),
                    ])
                    ->action(fn (array $data) => $this->createToken($data['name'])),
            ])
            ->actions([
                TableAction::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-m-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (PersonalAccessToken $record): void {
                        $record->delete();

                        Notification::make()->success()->title('Token revoked')->send();
                    }),
            ])
            ->emptyStateHeading('No API tokens yet')
            ->emptyStateDescription('Create a token to call the gateway over HTTP.');
    }

    /**
     * Tokens belonging to the current user that carry the invoke ability.
     */
    protected function tokensQuery(): Builder
    {
        $user = auth()->user();

        // No Sanctum table (or no user) → empty result rather than a 500.
        if (! $this->sanctumReady() || $user === null) {
            return PersonalAccessToken::query()->whereRaw('1 = 0');
        }

        $query = PersonalAccessToken::query()
            ->where('tokenable_id', $user?->getAuthIdentifier())
            ->where('tokenable_type', $user !== null ? $user->getMorphClass() : '')
            ->orderByDesc('created_at');

        // Filter to the invoke ability in PHP — abilities is a JSON column and
        // its storage varies; a LIKE keeps it portable.
        $query->where('abilities', 'like', '%'.static::ability().'%');

        return $query;
    }

    protected function createToken(string $name): void
    {
        $user = auth()->user();

        if ($user === null || ! method_exists($user, 'createToken')) {
            Notification::make()
                ->danger()
                ->title('Cannot create token')
                ->body('The authenticated user is not token-capable (missing HasApiTokens).')
                ->send();

            return;
        }

        $newToken = $user->createToken($name, [static::ability()]);

        Notification::make()
            ->success()
            ->title('Token created')
            ->body('Copy it now — it will not be shown again:'."\n\n".$newToken->plainTextToken)
            ->persistent()
            ->send();
    }

    /**
     * Expose readiness to the view so it can warn gracefully if Sanctum is not
     * migrated.
     */
    public function getViewData(): array
    {
        return [
            'sanctumReady' => $this->sanctumReady(),
        ];
    }
}
