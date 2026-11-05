<?php

namespace App\Filament\App\Resources\ServerConnections\Pages;

use App\Filament\App\Resources\ServerConnections\ServerConnectionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditServerConnection extends EditRecord
{
    protected static string $resource = ServerConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
