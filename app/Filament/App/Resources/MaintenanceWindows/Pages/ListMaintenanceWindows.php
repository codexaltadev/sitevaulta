<?php

namespace App\Filament\App\Resources\MaintenanceWindows\Pages;

use App\Filament\App\Resources\MaintenanceWindows\MaintenanceWindowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceWindows extends ListRecords
{
    protected static string $resource = MaintenanceWindowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New maintenance'),
        ];
    }
}
