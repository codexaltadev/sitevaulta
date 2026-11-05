<?php

namespace App\Filament\App\Resources\StorageConnections\Pages;

use App\Filament\App\Resources\StorageConnections\StorageConnectionResource;
use App\Models\StorageConnection;
use App\Services\Storage\StorageAdapterManager;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditStorageConnection extends EditRecord
{
    protected static string $resource = StorageConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkConnection')
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
            DeleteAction::make(),
        ];
    }
}
