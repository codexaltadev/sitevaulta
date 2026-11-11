<?php

namespace App\Filament\Admin\Resources\ProbeLocations\Pages;

use App\Filament\Admin\Resources\ProbeLocations\ProbeLocationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProbeLocation extends EditRecord
{
    protected static string $resource = ProbeLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
