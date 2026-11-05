<?php

namespace App\Filament\App\Resources\NotificationChannelResource\Pages;

use App\Filament\App\Resources\NotificationChannelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNotificationChannels extends ListRecords
{
    protected static string $resource = NotificationChannelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

