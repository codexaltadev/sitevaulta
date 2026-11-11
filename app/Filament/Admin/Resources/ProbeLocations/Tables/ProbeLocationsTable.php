<?php

namespace App\Filament\Admin\Resources\ProbeLocations\Tables;

use App\Models\ProbeLocation;
use App\Services\Monitoring\RemoteProbeClient;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProbeLocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Only the main server checks sites')
            ->emptyStateDescription('Add probe agents in other regions; an outage is then only reported when most locations agree.')
            ->columns([
                TextColumn::make('name')
                    ->weight('semibold')
                    ->description(fn (ProbeLocation $record): string => $record->region),
                TextColumn::make('url')
                    ->label('Agent address')
                    ->copyable(),
                TextColumn::make('health_status')
                    ->label('Health')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        ProbeLocation::HEALTHY => 'Answering',
                        ProbeLocation::UNREACHABLE => 'Not answering',
                        default => 'Not checked yet',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        ProbeLocation::HEALTHY => 'success',
                        ProbeLocation::UNREACHABLE => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (ProbeLocation $record): ?string => $record->health_status === ProbeLocation::UNREACHABLE ? $record->last_error : $record->last_seen_at?->diffForHumans()),
                TextColumn::make('public_ips')
                    ->label('IPs')
                    ->badge()
                    ->separator(','),
                IconColumn::make('is_enabled')
                    ->label('Enabled')
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('testConnection')
                    ->label('Test connection')
                    ->icon(Heroicon::OutlinedSignal)
                    ->color('gray')
                    ->action(function (ProbeLocation $record): void {
                        $healthy = app(RemoteProbeClient::class)->checkHealth($record);

                        $notification = Notification::make()->title($healthy ? "{$record->name} answered" : "{$record->name} did not answer");
                        $healthy
                            ? $notification->success()->body('Signed request accepted — it now helps confirm outages.')
                            : $notification->danger()->body((string) $record->last_error);
                        $notification->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
