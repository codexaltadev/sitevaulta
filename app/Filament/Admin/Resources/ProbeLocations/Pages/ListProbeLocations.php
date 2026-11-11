<?php

namespace App\Filament\Admin\Resources\ProbeLocations\Pages;

use App\Filament\Admin\Resources\ProbeLocations\ProbeLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProbeLocations extends ListRecords
{
    protected static string $resource = ProbeLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
