<?php

namespace App\Filament\App\Resources\StatusPages\Schemas;

use App\Models\StatusPage;
use App\Models\Workspace;
use App\Rules\BelongsToWorkspaceMonitors;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class StatusPageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Status page')
                    ->description('A public page your clients can bookmark. It shows service names and health only — never URLs, IPs or error details.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Page title')
                            ->placeholder('e.g. Acme Services Status')
                            ->required()
                            ->maxLength(150)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, Get $get, Set $set, ?StatusPage $record): void {
                                if ($record === null && blank($get('slug')) && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('Public address')
                            ->prefix(fn (): string => url('/status').'/')
                            ->required()
                            ->alphaDash()
                            ->minLength(3)
                            ->maxLength(80)
                            ->unique(ignoreRecord: true),

                        Textarea::make('description')
                            ->label('Short description')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),

                        Select::make('monitors')
                            ->label('Monitors shown on the page')
                            ->relationship('monitors', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->required()
                            ->rules(fn (): array => [new BelongsToWorkspaceMonitors(self::currentWorkspace())])
                            ->columnSpanFull(),
                    ]),

                Section::make('Access & display')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('password')
                            ->label('Password (optional)')
                            ->password()
                            ->revealable()
                            ->minLength(6)
                            ->maxLength(100)
                            ->helperText(fn (?StatusPage $record): string => $record?->isPasswordProtected()
                                ? 'Protected. Leave blank to keep the current password; use "Remove password" in the list to make it public.'
                                : 'Leave blank to make the page public.')
                            ->dehydrated(fn (?string $state): bool => filled($state)),

                        Toggle::make('is_published')
                            ->label('Published')
                            ->helperText('Unpublished pages return "not found".')
                            ->default(true),

                        Toggle::make('show_uptime_bars')
                            ->label('Show 90-day uptime bars')
                            ->default(true),

                        Toggle::make('show_incident_history')
                            ->label('Show incident history (last 14 days)')
                            ->default(true),
                    ]),
            ]);
    }

    private static function currentWorkspace(): ?Workspace
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace ? $workspace : null;
    }
}
