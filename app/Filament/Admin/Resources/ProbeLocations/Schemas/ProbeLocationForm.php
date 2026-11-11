<?php

namespace App\Filament\Admin\Resources\ProbeLocations\Schemas;

use App\Models\ProbeLocation;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ProbeLocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Probe location')
                    ->description('Another server running this application as a probe agent. Before a monitor is marked down, every enabled location re-checks it and the majority decides.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->placeholder('Singapore')
                            ->required()
                            ->maxLength(80),
                        TextInput::make('region')
                            ->placeholder('ap-southeast-1')
                            ->required()
                            ->maxLength(40),
                        TextInput::make('url')
                            ->label('Agent address')
                            ->placeholder('https://sg-probe.example.com')
                            ->url()
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('secret')
                            ->label('Shared secret')
                            ->password()
                            ->revealable()
                            ->copyable()
                            ->default(fn (): string => ProbeLocation::generateSecret())
                            ->minLength(32)
                            ->maxLength(128)
                            ->required()
                            ->columnSpanFull()
                            ->helperText(new HtmlString('On the agent server set <code>MONITORING_AGENT_MODE=true</code> and <code>MONITORING_AGENT_SECRET=</code> to this value. Requests are signed with it; the agent refuses anything else.')),
                        TagsInput::make('public_ips')
                            ->label('Public IP addresses')
                            ->placeholder('Add the IP checks leave from')
                            ->nestedRecursiveRules(['ip'])
                            ->helperText('Listed on the public monitoring-IPs page so customers can allow them in their firewall.')
                            ->columnSpanFull(),
                        Toggle::make('is_enabled')
                            ->label('Enabled')
                            ->helperText('Disabled locations are not asked to confirm outages.')
                            ->default(true),
                        TextEntry::make('health')
                            ->label('Last health check')
                            ->state(fn (?ProbeLocation $record): string => match ($record?->health_status) {
                                ProbeLocation::HEALTHY => '✓ Answering — last seen '.$record->last_seen_at?->diffForHumans(),
                                ProbeLocation::UNREACHABLE => '✗ Not answering: '.($record->last_error ?? 'unknown error'),
                                default => 'Not checked yet — use "Test connection".',
                            })
                            ->visible(fn (?ProbeLocation $record): bool => $record !== null),
                    ]),
            ]);
    }
}
