<?php

namespace App\Filament\App\Resources\MaintenanceWindows\Tables;

use App\Models\MaintenanceWindow;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MaintenanceWindowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('workspace')->withCount('monitors'))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No maintenance planned')
            ->emptyStateDescription('Plan backups, restarts or upgrades so they never open incidents or wake anyone up.')
            ->columns([
                TextColumn::make('name')
                    ->weight('semibold')
                    ->searchable(),
                TextColumn::make('schedule')
                    ->label('Schedule')
                    ->state(fn (MaintenanceWindow $record): string => $record->scheduleSummary()),
                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->state(fn (MaintenanceWindow $record): string => self::stateLabel($record))
                    ->color(fn (string $state): string => match (true) {
                        $state === 'In progress' => 'warning',
                        str_starts_with($state, 'Next') => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('monitors_count')
                    ->label('Monitors')
                    ->counts('monitors'),
                ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function stateLabel(MaintenanceWindow $window): string
    {
        if (! $window->is_active) {
            return 'Paused';
        }

        if ($window->isInProgressAt(now())) {
            return 'In progress';
        }

        $next = $window->nextStartAfter(now());

        return $next === null
            ? 'Ended'
            : 'Next: '.$next->setTimezone($window->workspace?->timezone ?: config('app.timezone'))->format('M j, H:i');
    }
}
