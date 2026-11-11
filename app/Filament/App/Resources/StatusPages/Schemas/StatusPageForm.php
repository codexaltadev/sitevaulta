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
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
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

                        Toggle::make('allow_subscriptions')
                            ->label('Let visitors subscribe by email')
                            ->helperText('Subscribers confirm their address first, then get an email when a service goes down or recovers and when you post an announcement.')
                            ->default(true),
                    ]),

                Section::make('Custom domain')
                    ->description('Show this page on your own address, e.g. status.yourcompany.com.')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        TextInput::make('custom_domain')
                            ->label('Domain')
                            ->placeholder('status.yourcompany.com')
                            ->prefixIcon(Heroicon::OutlinedGlobeAlt)
                            ->maxLength(191)
                            ->regex('/^(https?:\/\/)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\/?$/i')
                            ->validationMessages(['regex' => 'Enter a domain such as status.yourcompany.com.'])
                            ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                if (StatusPage::normalizeDomain($value) === mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))) {
                                    $fail('Use your own domain, not this application\'s address.');
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => StatusPage::normalizeDomain($state))
                            ->unique(ignoreRecord: true)
                            ->helperText(fn (): HtmlString => new HtmlString(
                                'In your DNS, add a <strong>CNAME</strong> record for this domain pointing to <code>'.e((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'</code>. '
                                .'Leave empty to use only the '.e(url('/status')).'/… address.'
                            )),

                        TextEntry::make('custom_domain_dns')
                            ->label('DNS check')
                            ->state(fn (?StatusPage $record): string => match ($record?->customDomainPointsHere()) {
                                true => '✓ '.$record->custom_domain.' points to this application.',
                                false => '✗ '.$record->custom_domain.' does not point here yet. DNS changes can take up to an hour.',
                                default => 'Save a domain to check its DNS.',
                            })
                            ->visible(fn (?StatusPage $record): bool => filled($record?->custom_domain)),
                    ]),
            ]);
    }

    private static function currentWorkspace(): ?Workspace
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace ? $workspace : null;
    }
}
