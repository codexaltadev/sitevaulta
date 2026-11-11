<?php

namespace App\Filament\App\Resources\MaintenanceWindows\Pages;

use App\Filament\App\Resources\MaintenanceWindows\MaintenanceWindowResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceWindow extends CreateRecord
{
    protected static string $resource = MaintenanceWindowResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
