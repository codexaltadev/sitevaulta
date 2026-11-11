<?php

namespace App\Filament\App\Resources\MonitorIncidents\Tables;

use App\Enums\MonitorIncidentStatus;
use App\Filament\App\Resources\MonitorIncidents\MonitorIncidentResource;
use App\Filament\App\Resources\Monitors\Pages\ViewMonitor;
use App\Models\MonitorIncident;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MonitorIncidentsTable
{
    /**
     * @var array<string, string>
     */
    public const ROOT_CAUSE_TYPES = [
        'http' => 'HTTP error',
        'dns' => 'DNS',
        'timeout' => 'Timeout',
        'connection' => 'Connection',
        'ssl' => 'SSL',
        'keyword' => 'Keyword',
        'port' => 'Port',
        'ping' => 'Ping',
        'heartbeat' => 'Missed heartbeat',
        'domain' => 'Domain expiry',
        'blocked' => 'Blocked destination',
        'firewall' => 'Blocked by firewall / WAF',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('monitor:id,name'))
            ->defaultSort('started_at', 'desc')
            ->recordUrl(fn (MonitorIncident $record): string => MonitorIncidentResource::getUrl('view', ['record' => $record]))
            ->poll('60s')
            ->emptyStateHeading('No incidents yet')
            ->emptyStateDescription('When a monitor goes down and a re-check confirms it, the incident shows up here.')
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (MonitorIncidentStatus $state): string => $state->label())
                    ->color(fn (MonitorIncidentStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('monitor.name')
                    ->label('Monitor')
                    ->weight('semibold')
                    ->searchable(),
                TextColumn::make('root_cause')
                    ->label('Root cause')
                    ->description(fn (MonitorIncident $record): string => self::ROOT_CAUSE_TYPES[$record->root_cause_type] ?? ucfirst($record->root_cause_type))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('M j, Y, H:i', timezone: self::workspaceTimezone())
                    ->sortable(),
                TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->dateTime('M j, Y, H:i', timezone: self::workspaceTimezone())
                    ->placeholder('Not yet resolved')
                    ->sortable(),
                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->state(fn (MonitorIncident $record): string => ViewMonitor::formatDuration(
                        $record->duration_seconds ?? (int) $record->started_at->diffInSeconds(now())
                    ))
                    ->sortable(),
                TextColumn::make('is_excluded_from_reports')
                    ->label('Visibility')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Excluded' : 'Included')
                    ->color(fn (bool $state): string => $state ? 'gray' : 'success'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        MonitorIncidentStatus::Ongoing->value => 'Ongoing',
                        MonitorIncidentStatus::Resolved->value => 'Resolved',
                    ]),
                SelectFilter::make('root_cause_type')
                    ->label('Root cause')
                    ->options(self::ROOT_CAUSE_TYPES),
                SelectFilter::make('monitor')
                    ->relationship('monitor', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_excluded_from_reports')
                    ->label('Visibility')
                    ->trueLabel('Excluded only')
                    ->falseLabel('Included only'),
            ])
            ->recordActions([
                Action::make('toggleVisibility')
                    ->label(fn (MonitorIncident $record): string => $record->is_excluded_from_reports ? 'Include' : 'Exclude')
                    ->icon(fn (MonitorIncident $record): Heroicon => $record->is_excluded_from_reports ? Heroicon::OutlinedEye : Heroicon::OutlinedEyeSlash)
                    ->color('gray')
                    ->tooltip('Excluded incidents do not count in client reports (e.g. false alarms or planned work).')
                    ->action(fn (MonitorIncident $record) => $record->update([
                        'is_excluded_from_reports' => ! $record->is_excluded_from_reports,
                    ])),
            ]);
    }

    private static function workspaceTimezone(): ?string
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace ? $workspace->timezone : null;
    }
}
