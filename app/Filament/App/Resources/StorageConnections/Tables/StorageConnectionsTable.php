<?php

namespace App\Filament\App\Resources\StorageConnections\Tables;

use App\Models\StorageConnection;
use App\Services\Forecast\StorageForecastService;
use App\Services\Storage\StorageAdapterManager;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StorageConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Storage Name')
                    ->searchable(),
                TextColumn::make('driver')
                    ->label('Provider')
                    ->badge(),
                TextColumn::make('quota_used_bytes')
                    ->label('Tracked Usage')
                    ->formatStateUsing(fn ($state): string => StorageForecastService::formatBytes((int) $state)),
                TextColumn::make('quota_total_bytes')
                    ->label('Total Quota')
                    ->formatStateUsing(fn ($state): string => $state ? StorageForecastService::formatBytes((int) $state) : 'Unlimited'),
                TextColumn::make('health_status')
                    ->label('Health')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'healthy' => 'success',
                        'error', 'quota_exceeded' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->recordActions([
                Action::make('testStorage')
                    ->label('Check Connection')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('info')
                    ->action(function (StorageConnection $record): void {
                        $res = StorageAdapterManager::testConnection($record);
                        if ($res['ok']) {
                            Notification::make()
                                ->title('✅ Storage Connection Verified')
                                ->body($res['message'])
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('❌ Storage Verification Error')
                                ->body($res['message'])
                                ->danger()
                                ->send();
                        }
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ]);

    }
}
