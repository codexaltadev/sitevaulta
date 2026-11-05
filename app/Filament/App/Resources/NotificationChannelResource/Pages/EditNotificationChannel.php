<?php

namespace App\Filament\App\Resources\NotificationChannelResource\Pages;

use App\Filament\App\Resources\NotificationChannelResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNotificationChannel extends EditRecord
{
    protected static string $resource = NotificationChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

