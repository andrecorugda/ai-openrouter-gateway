<?php

declare(strict_types=1);

namespace Andre\AiGateway\Filament\Resources;

use Andre\AiGateway\Filament\Forms\Components\PromptComposer;
use Andre\AiGateway\Filament\Resources\AiIntegrationResource\Pages;
use Andre\AiGateway\Models\AiIntegration;
use Andre\AiGateway\Models\AiIntegrationVersion;
use Andre\AiGateway\Services\AiGateway;
use Andre\AiGateway\Services\AiIntegrationService;
use Andre\AiGateway\Services\OpenRouterModelCatalog;
use Andre\AiGateway\Services\PromptBuilderService;
use Andre\AiGateway\Services\PromptRenderer;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Admin UI for the AI integration registry.
 *
 * Each row is a use case (slug + provider + guardrails). The *editable surface*
 * — system prompt, models, params, prompt args, server tools — lives on the
 * active {@see AiIntegrationVersion}; saving here mints + activates a new
 * version via {@see AiIntegrationService::saveVersion()} so history is kept.
 *
 * The form intentionally surfaces version fields as plain form fields; the
 * Create/Edit pages split registry vs. version columns at save time and merge
 * the active version back in on load.
 */
class AiIntegrationResource extends Resource
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    public static function getModel(): string
    {
        /** @var class-string<Model> */
        return config('ai-gateway.models.integration', AiIntegration::class);
    }

    public static function getModelLabel(): string
    {
        return 'AI integration';
    }

    public static function getNavigationGroup(): ?string
    {
        return config('ai-gateway.filament.navigation_group', 'AI & Automation');
    }

    public static function getNavigationSort(): ?int
    {
        return config('ai-gateway.filament.navigation_sort', 50);
    }

    /** The 6 prompt-arg types, as a select option map. */
    public static function argTypeOptions(): array
    {
        return array_combine(PromptRenderer::VALID_TYPES, PromptRenderer::VALID_TYPES);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([

            // Row 1: Identity + Models side by side.
            Schemas\Components\Grid::make(2)->schema([

                // (a) Identity ----------------------------------------------------
                Schemas\Components\Section::make('Identity')
                    ->description('Stable registry fields. The slug is the call key and cannot change after creation.')
                    ->schema([
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->regex('/^[a-z][a-z0-9_-]*$/')
                            ->maxLength(64)
                            ->helperText('Lowercase, dashes/underscores only. Used as the invocation key, e.g. "lead-summary".')
                            // Immutable once created: shown read-only and excluded from the update payload.
                            ->disabled(fn (?object $record): bool => $record !== null)
                            ->dehydrated(fn (?object $record): bool => $record === null),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(160),
                        Forms\Components\Textarea::make('description')
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpan(1),

                // (b) Models ------------------------------------------------------
                Schemas\Components\Section::make('Models')
                    ->description('Pick from the live OpenRouter catalog. The primary is tried first; fallbacks follow in order.')
                    ->schema([
                        Forms\Components\Select::make('primary_model')
                            ->label('Primary model')
                            ->options(fn (): array => app(OpenRouterModelCatalog::class)->options())
                            ->searchable()
                            ->required()
                            ->live()
                            ->default((string) config('ai-gateway.default_model', 'anthropic/claude-sonnet-4'))
                            ->helperText(function (Get $get): ?string {
                                $id = (string) ($get('primary_model') ?? '');
                                if ($id === '') {
                                    return null;
                                }
                                $model = app(OpenRouterModelCatalog::class)->find($id);
                                if ($model === null) {
                                    return null;
                                }

                                return static::modelMetaSummary($model);
                            })
                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                // Seed the generation params with this model's supported
                                // params WITHOUT overwriting any keys the admin already set.
                                $id = (string) ($state ?? '');
                                if ($id === '') {
                                    return;
                                }
                                $current = is_array($get('default_params')) ? $get('default_params') : [];
                                $suggested = app(OpenRouterModelCatalog::class)->defaultParametersFor($id);

                                $merged = $current;
                                foreach ($suggested as $param => $value) {
                                    if (! array_key_exists($param, $merged)) {
                                        $merged[$param] = (string) $value;
                                    }
                                }

                                $set('default_params', $merged);
                            }),
                        Forms\Components\Select::make('fallback_models')
                            ->label('Fallback models')
                            ->options(fn (): array => app(OpenRouterModelCatalog::class)->options())
                            ->multiple()
                            ->searchable()
                            ->helperText('Tried in order if the primary is unavailable.'),
                    ])
                    ->columns(1)
                    ->columnSpan(1),

            ]),

            // (c) Prompt + variables -----------------------------------------
            // Variables are declared via a modal ("Manage variables"); the
            // prompt composer's side panel lists them for click-to-insert.
            Schemas\Components\Section::make('System prompt')
                ->description('The template rendered before each call. Use {{snake_case}} placeholders for runtime variables.')
                ->headerActions([
                    static::manageVariablesAction(),
                    static::draftWithAiAction(),
                ])
                ->schema([
                    // Holds the declared variables in form state (edited via the
                    // "Manage variables" modal, read by the composer's panel).
                    Forms\Components\Hidden::make('prompt_args'),
                    PromptComposer::make('system_prompt')
                        ->variables(fn (Get $get): array => collect($get('prompt_args') ?? [])
                            ->pluck('name')
                            ->filter()
                            ->values()
                            ->all())
                        ->required()
                        ->columnSpanFull()
                        ->helperText('Placeholders like {{company_name}} are filled from the Variables above.'),
                    Forms\Components\Toggle::make('system_prompt_cacheable')
                        ->default(true)
                        ->helperText('Send the system prompt with a cache_control marker so this provider can cache it.')
                        ->visible(fn (Get $get): bool => app(OpenRouterModelCatalog::class)
                            ->cachingMode((string) ($get('primary_model') ?? '')) === 'explicit'),
                    Forms\Components\Placeholder::make('caching_auto')
                        ->label('Prompt caching')
                        ->content('Cached automatically by this provider.')
                        ->visible(fn (Get $get): bool => app(OpenRouterModelCatalog::class)
                            ->cachingMode((string) ($get('primary_model') ?? '')) === 'automatic'),
                ]),

            // (e) Generation params ------------------------------------------
            Schemas\Components\Section::make('Generation parameters')
                ->description('Default OpenRouter params. max_tokens and temperature live here.')
                ->schema([
                    Forms\Components\KeyValue::make('default_params')
                        // Seed from the pre-selected default model on create, so the
                        // params aren't empty until the model is toggled (the
                        // afterStateUpdated seeder only fires on a change).
                        ->default(fn (): array => array_map(
                            static fn ($v): string => (string) $v,
                            app(OpenRouterModelCatalog::class)->defaultParametersFor((string) config('ai-gateway.default_model', '')),
                        ))
                        ->keyLabel('param')
                        ->valueLabel('value')
                        ->reorderable()
                        ->helperText(function (Get $get): string {
                            $id = (string) ($get('primary_model') ?? '');
                            $base = 'e.g. max_tokens = 1024, temperature = 0.7, top_p = 0.9';
                            if ($id === '') {
                                return $base;
                            }
                            $count = count(app(OpenRouterModelCatalog::class)->supportedParameters($id));

                            return $count > 0
                                ? "This model supports {$count} params. ".$base
                                : $base;
                        })
                        ->columnSpanFull(),
                ]),

            // Row: Server tools + Limits + Visibility in three columns.
            Schemas\Components\Grid::make(3)->schema([

                // (f) Server tools ------------------------------------------------
                Schemas\Components\Section::make('Server tools')
                    ->description('OpenRouter-hosted tools the model may call. Persisted into the server_tools shape.')
                    ->schema([
                        Schemas\Components\Fieldset::make('Web search')
                            ->schema([
                                Forms\Components\Toggle::make('server_tools_web_search_enabled')
                                    ->label('Enable web search'),
                                Forms\Components\Select::make('server_tools_web_search_engine')
                                    ->label('Engine')
                                    ->options([
                                        'auto' => 'auto',
                                        'native' => 'native',
                                        'exa' => 'exa',
                                        'firecrawl' => 'firecrawl',
                                        'parallel' => 'parallel',
                                    ])
                                    ->default('auto')
                                    ->visible(fn (Get $get): bool => (bool) $get('server_tools_web_search_enabled')),
                                Forms\Components\TextInput::make('server_tools_web_search_max_results')
                                    ->label('Max results')
                                    ->numeric()
                                    ->minValue(1)
                                    ->visible(fn (Get $get): bool => (bool) $get('server_tools_web_search_enabled')),
                            ])
                            ->columns(1),
                        Schemas\Components\Fieldset::make('Web fetch')
                            ->schema([
                                Forms\Components\Toggle::make('server_tools_web_fetch_enabled')
                                    ->label('Enable web fetch'),
                                Forms\Components\Select::make('server_tools_web_fetch_engine')
                                    ->label('Engine')
                                    ->options([
                                        'auto' => 'auto',
                                        'native' => 'native',
                                        'exa' => 'exa',
                                        'firecrawl' => 'firecrawl',
                                        'parallel' => 'parallel',
                                    ])
                                    ->default('auto')
                                    ->visible(fn (Get $get): bool => (bool) $get('server_tools_web_fetch_enabled')),
                            ])
                            ->columns(1),
                    ])
                    ->columnSpan(1),

                // (g) Limits ------------------------------------------------------
                Schemas\Components\Section::make('Limits')
                    ->schema([
                        Forms\Components\TextInput::make('rate_limit_per_minute')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Blank = config default.'),
                        Forms\Components\TextInput::make('max_daily_cost_usd')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('$')
                            ->helperText('Blank = config default. Rolling 24h USD ceiling.'),
                    ])
                    ->columns(1)
                    ->columnSpan(1),

                // (h) Visibility & status ----------------------------------------
                Schemas\Components\Section::make('Visibility & status')
                    ->schema([
                        Forms\Components\Select::make('visibility')
                            ->options([
                                'internal' => 'Internal only',
                                'public' => 'Public (HTTP API)',
                                'both' => 'Both',
                            ])
                            ->default('internal')
                            ->required()
                            ->helperText('"public"/"both" are reachable over the Sanctum HTTP API.'),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                        Forms\Components\Toggle::make('supports_vision'),
                        Forms\Components\Toggle::make('supports_tools'),
                        Forms\Components\Toggle::make('is_conversational')
                            ->label('Conversational')
                            ->helperText('Enables the /start + /converse thread API.')
                            ->live(),
                        Forms\Components\TextInput::make('conversation_ttl_minutes')
                            ->label('Thread TTL (minutes)')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder((string) config('ai-gateway.conversations.default_ttl_minutes', 2880))
                            ->helperText('Idle threads expire after this. Blank = config default.')
                            ->visible(fn (Get $get): bool => (bool) $get('is_conversational')),
                    ])
                    ->columns(1)
                    ->columnSpan(1),

            ]),
        ])
            // Single-column root so the top-level rows stack; the explicit
            // Grid::make(2)/Grid::make(3) above lay out the side-by-side
            // sections. (Filament v4/v5 no longer makes sections span full
            // width by default, which otherwise jumbles the layout.)
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('model')
                    ->label('Model')
                    ->getStateUsing(function (object $record): string {
                        $models = $record->models ?? [];
                        $primary = $models[0] ?? '—';
                        $extra = max(0, count($models) - 1);

                        return $extra > 0 ? $primary.'  +'.$extra : (string) $primary;
                    })
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('visibility')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'public' => 'success',
                        'both' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\ToggleColumn::make('is_active'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\SelectFilter::make('visibility')
                    ->options([
                        'internal' => 'Internal',
                        'public' => 'Public',
                        'both' => 'Both',
                    ]),
            ])
            ->actions([
                EditAction::make(),
                static::testAction(),
                static::versionsAction(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            AiIntegrationResource\RelationManagers\InvocationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiIntegrations::route('/'),
            'create' => Pages\CreateAiIntegration::route('/create'),
            'edit' => Pages\EditAiIntegration::route('/{record}/edit'),
        ];
    }

    // -------------------------------------------------------------------------
    // Shared actions
    // -------------------------------------------------------------------------

    /**
     * "Manage variables" — a form header action that edits the declared
     * `prompt_args` in a modal (kept out of the main layout since the prompt
     * composer's side panel already lists them for click-to-insert).
     */
    public static function manageVariablesAction(): Action
    {
        return Action::make('manageVariables')
            ->label('Manage variables')
            ->icon('heroicon-m-variable')
            ->modalHeading('Declare variables')
            ->modalWidth('4xl')
            ->modalSubmitActionLabel('Done')
            ->fillForm(fn (Get $get): array => ['prompt_args' => $get('prompt_args') ?? []])
            ->form([
                Forms\Components\Repeater::make('prompt_args')
                    ->label('Prompt variables')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->regex('/^[a-z][a-z0-9_]*$/')
                            ->maxLength(32)
                            ->helperText('snake_case, max 32 chars.'),
                        Forms\Components\Select::make('type')
                            ->options(static::argTypeOptions())
                            ->default('string')
                            ->required(),
                        Forms\Components\Toggle::make('required')
                            ->default(true)
                            ->inline(false),
                        Forms\Components\TextInput::make('default')
                            ->label('Default')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('description')
                            ->maxLength(255),
                    ])
                    ->columns(5)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->addActionLabel('Add variable')
                    ->collapsible()
                    ->defaultItems(0)
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, Set $set): void {
                $set('prompt_args', array_values($data['prompt_args'] ?? []));
            });
    }

    /**
     * "Draft with AI" — a form header action on the system-prompt section.
     * Only shown when the prompt builder is available (key set + enabled).
     */
    public static function draftWithAiAction(): Action
    {
        return Action::make('draftWithAi')
            ->label('Draft with AI')
            ->icon('heroicon-m-sparkles')
            ->visible(fn (): bool => app(PromptBuilderService::class)->isAvailable())
            ->form([
                Forms\Components\Textarea::make('brief')
                    ->label('Describe what this integration should do')
                    ->required()
                    ->rows(4)
                    ->placeholder('e.g. Summarize a sales lead into 3 bullet points given the company name and notes.'),
            ])
            ->action(function (array $data, Get $get, Set $set): void {
                try {
                    $result = app(PromptBuilderService::class)->build(
                        $data['brief'],
                        (string) ($get('system_prompt') ?? ''),
                    );
                } catch (Throwable $e) {
                    Notification::make()
                        ->danger()
                        ->title('Prompt builder failed')
                        ->body($e->getMessage())
                        ->send();

                    return;
                }

                $set('system_prompt', $result['system_prompt']);
                $set('prompt_args', $result['prompt_args']);

                Notification::make()
                    ->success()
                    ->title('Draft inserted')
                    ->body($result['notes'] ?? 'Review the prompt and variables, then save.')
                    ->send();
            });
    }

    /**
     * "Test" — run a one-off invocation against the active version using a
     * modal form built from its prompt_args. Reused on the table and the edit
     * header. Disabled when there is no active version.
     */
    public static function testAction(): Action
    {
        return Action::make('test')
            ->label('Test')
            ->icon('heroicon-m-play')
            ->color('gray')
            ->disabled(fn (object $record): bool => $record->activeVersion === null)
            ->modalHeading(fn (object $record): string => 'Test "'.$record->name.'"')
            ->modalSubmitActionLabel('Run')
            ->form(fn (object $record): array => static::testFormSchema($record))
            ->action(fn (object $record, array $data): mixed => static::runTest($record, $data));
    }

    /**
     * Shared "run a test invocation" logic for both the table row action and
     * the edit-page header action. Splits the collected modal fields into
     * prompt args + an optional extra message, calls the gateway against the
     * record's active version, and reports the result via a notification.
     *
     * @param  array<string,mixed>  $data
     */
    public static function runTest(object $record, array $data): void
    {
        $version = $record->activeVersion;
        if ($version === null) {
            Notification::make()->danger()->title('No active version to test')->send();

            return;
        }

        // Split the collected fields: prompt args (arg_*) vs. extra message.
        $args = [];
        foreach (($version->prompt_args ?? []) as $arg) {
            $name = $arg['name'] ?? null;
            if (! is_string($name)) {
                continue;
            }
            $key = 'arg_'.$name;
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $args[$name] = static::castArgForTest($data[$key], (string) ($arg['type'] ?? 'string'));
            }
        }

        $messages = [];
        if (! empty($data['extra_message'])) {
            $messages[] = ['role' => 'user', 'content' => $data['extra_message']];
        }

        try {
            $result = app(AiGateway::class)->invokeVersion($record, $version, $args, $messages, [
                '_caller_type' => 'admin-test',
                '_caller_id' => (string) (auth()->id() ?? 'console'),
            ]);
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Test invocation failed')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        }

        $usage = $result->usage;
        $body = sprintf(
            "%s\n\nmodel: %s | tokens: %s→%s | cost: $%s | %s ms",
            mb_strimwidth($result->text, 0, 600, '…'),
            $result->model_used,
            $usage['prompt_tokens'] ?? '?',
            $usage['completion_tokens'] ?? '?',
            $result->cost_usd !== null ? number_format($result->cost_usd, 6) : '?',
            $result->latency_ms ?? '?',
        );

        Notification::make()
            ->success()
            ->title('Test succeeded')
            ->body($body)
            ->persistent()
            ->send();
    }

    /**
     * Build one modal field per prompt arg, plus an optional extra user message.
     *
     * @return array<int,Component>
     */
    public static function testFormSchema(object $record): array
    {
        $fields = [];

        foreach (($record->activeVersion?->prompt_args ?? []) as $arg) {
            $name = $arg['name'] ?? null;
            if (! is_string($name)) {
                continue;
            }
            $type = (string) ($arg['type'] ?? 'string');
            $required = (bool) ($arg['required'] ?? false);
            $label = $name.($required ? ' *' : '');
            $help = $arg['description'] ?? null;

            $field = match ($type) {
                'boolean' => Forms\Components\Toggle::make('arg_'.$name)->label($name),
                'number' => Forms\Components\TextInput::make('arg_'.$name)->label($label)->numeric(),
                'array', 'object', 'json' => Forms\Components\Textarea::make('arg_'.$name)
                    ->label($label.' (JSON)')
                    ->rows(3),
                default => Forms\Components\TextInput::make('arg_'.$name)->label($label),
            };

            if ($required && $type !== 'boolean') {
                $field = $field->required();
            }
            if (is_string($help) && $help !== '') {
                $field = $field->helperText($help);
            }

            $fields[] = $field;
        }

        $fields[] = Forms\Components\Textarea::make('extra_message')
            ->label('Extra user message (optional)')
            ->rows(3)
            ->helperText('Appended as a user turn after the rendered system prompt.');

        return $fields;
    }

    /**
     * Coerce a raw modal value into the type the renderer expects.
     */
    public static function castArgForTest(mixed $value, string $type): mixed
    {
        return match ($type) {
            'number' => is_numeric($value) ? $value + 0 : $value,
            'boolean' => (bool) $value,
            'array', 'object' => is_string($value) ? (json_decode($value, true) ?? $value) : $value,
            // 'json' stays a string — the renderer validates it as a JSON string.
            default => $value,
        };
    }

    /**
     * "Versions" — list this integration's versions with an Activate button.
     */
    public static function versionsAction(): Action
    {
        return Action::make('versions')
            ->label('Versions')
            ->icon('heroicon-m-clock')
            ->color('gray')
            ->modalHeading(fn (object $record): string => 'Versions of "'.$record->name.'"')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->infolist(function (object $record): array {
                // Render a compact, read-only list with inline Activate actions.
                $entries = [];
                foreach ($record->versions as $version) {
                    $entries[] = TextEntry::make('v'.$version->id)
                        ->hiddenLabel()
                        ->state(sprintf(
                            'v%d — %s%s',
                            $version->version_number,
                            $version->created_at?->toDayDateTimeString() ?? 'unknown date',
                            $version->is_active ? '  (active)' : '',
                        ))
                        ->badge()
                        ->color($version->is_active ? 'success' : 'gray')
                        ->suffixActions(array_values(array_filter([
                            $version->is_active
                                ? null
                                : Action::make('activate_'.$version->id)
                                    ->label('Activate')
                                    ->icon('heroicon-m-check')
                                    ->requiresConfirmation()
                                    ->action(function () use ($version): void {
                                        app(AiIntegrationService::class)->activate($version);

                                        Notification::make()
                                            ->success()
                                            ->title('Version v'.$version->version_number.' activated')
                                            ->send();
                                    }),
                        ])));
                }

                return $entries;
            });
    }

    // -------------------------------------------------------------------------
    // Save helpers — shared by Create & Edit pages.
    // -------------------------------------------------------------------------

    /** Registry columns owned by the AiIntegration model. */
    public const REGISTRY_KEYS = [
        'slug', 'name', 'description', 'provider', 'visibility',
        'is_active', 'supports_vision', 'supports_tools',
        'rate_limit_per_minute', 'max_daily_cost_usd',
        'is_conversational', 'conversation_ttl_minutes',
    ];

    /** Version-only keys that must NOT be passed to the model's save/update. */
    public const VERSION_KEYS = [
        'system_prompt', 'system_prompt_cacheable', 'models',
        'default_params', 'prompt_args', 'server_tools',
    ];

    /**
     * Pull the version attributes (in the shape saveVersion expects) out of a
     * flat form-data array, reassembling the nested server_tools shape.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function extractVersionAttributes(array $data): array
    {
        return [
            'system_prompt' => (string) ($data['system_prompt'] ?? ''),
            'system_prompt_cacheable' => (bool) ($data['system_prompt_cacheable'] ?? true),
            'models' => static::assembleModels($data),
            'default_params' => static::normalizeParams($data['default_params'] ?? null),
            'prompt_args' => array_values($data['prompt_args'] ?? []),
            'server_tools' => static::assembleServerTools($data),
        ];
    }

    /**
     * Build the version's ordered `models` list from the two form-only Selects:
     * primary first, then fallbacks, de-duped while preserving order.
     *
     * @param  array<string,mixed>  $data
     * @return array<int,string>
     */
    public static function assembleModels(array $data): array
    {
        $primary = $data['primary_model'] ?? null;
        $fallbacks = is_array($data['fallback_models'] ?? null) ? $data['fallback_models'] : [];

        $ordered = [];
        if (is_string($primary) && $primary !== '') {
            $ordered[] = $primary;
        }
        foreach ($fallbacks as $model) {
            if (is_string($model) && $model !== '') {
                $ordered[] = $model;
            }
        }

        return array_values(array_unique($ordered));
    }

    /**
     * Human-readable context-length + pricing summary for a catalog model row,
     * used as the primary-model select's helper text.
     *
     * @param  array<string,mixed>  $model
     */
    public static function modelMetaSummary(array $model): string
    {
        $parts = [];

        $context = $model['context_length'] ?? null;
        if (is_int($context) && $context > 0) {
            $parts[] = 'Context: '.number_format($context).' tokens';
        }

        $pricing = is_array($model['pricing'] ?? null) ? $model['pricing'] : [];
        $prompt = $pricing['prompt'] ?? null;
        $completion = $pricing['completion'] ?? null;
        if (is_numeric($prompt) && is_numeric($completion)) {
            // OpenRouter prices are per-token USD; show per-million for readability.
            $in = number_format(((float) $prompt) * 1_000_000, 2);
            $out = number_format(((float) $completion) * 1_000_000, 2);
            $parts[] = "Pricing: \${$in}/M in, \${$out}/M out";
        }

        return $parts === [] ? 'No catalog metadata available.' : implode('  •  ', $parts);
    }

    /**
     * Strip version-only keys (and the flattened server-tool toggles) so only
     * registry columns reach the model.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function onlyRegistryAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip(static::REGISTRY_KEYS));
    }

    /**
     * KeyValue gives string values; leave them as strings (the gateway parses
     * numerics/JSON downstream). null/empty → null so the column stays clean.
     *
     * @return array<string,mixed>|null
     */
    public static function normalizeParams(mixed $params): ?array
    {
        if (! is_array($params) || $params === []) {
            return null;
        }

        return $params;
    }

    /**
     * Reassemble the nested server_tools shape from the flattened form toggles.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>|null
     */
    public static function assembleServerTools(array $data): ?array
    {
        $tools = [];

        if (! empty($data['server_tools_web_search_enabled'])) {
            $search = ['enabled' => true];
            if (! empty($data['server_tools_web_search_engine'])) {
                $search['engine'] = $data['server_tools_web_search_engine'];
            }
            if (isset($data['server_tools_web_search_max_results']) && $data['server_tools_web_search_max_results'] !== '' && $data['server_tools_web_search_max_results'] !== null) {
                $search['max_results'] = (int) $data['server_tools_web_search_max_results'];
            }
            $tools['web_search'] = $search;
        }

        if (! empty($data['server_tools_web_fetch_enabled'])) {
            $fetch = ['enabled' => true];
            if (! empty($data['server_tools_web_fetch_engine'])) {
                $fetch['engine'] = $data['server_tools_web_fetch_engine'];
            }
            $tools['web_fetch'] = $fetch;
        }

        return $tools === [] ? null : $tools;
    }

    /**
     * Flatten a stored server_tools shape back into the form's toggle fields.
     *
     * @param  array<string,mixed>|null  $serverTools
     * @return array<string,mixed>
     */
    public static function flattenServerTools(?array $serverTools): array
    {
        $serverTools ??= [];
        $search = $serverTools['web_search'] ?? [];
        $fetch = $serverTools['web_fetch'] ?? [];

        return [
            'server_tools_web_search_enabled' => (bool) ($search['enabled'] ?? false),
            'server_tools_web_search_engine' => $search['engine'] ?? 'auto',
            'server_tools_web_search_max_results' => $search['max_results'] ?? null,
            'server_tools_web_fetch_enabled' => (bool) ($fetch['enabled'] ?? false),
            'server_tools_web_fetch_engine' => $fetch['engine'] ?? 'auto',
        ];
    }
}
