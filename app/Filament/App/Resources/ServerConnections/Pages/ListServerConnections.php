<?php

namespace App\Filament\App\Resources\ServerConnections\Pages;

use App\Filament\App\Resources\ServerConnections\ServerConnectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServerConnections extends ListRecords
{
    protected static string $resource = ServerConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
