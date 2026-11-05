<?php

namespace App\Filament\App\Resources\ClientReportResource\Pages;

use App\Filament\App\Resources\ClientReportResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditClientReport extends EditRecord
{
    protected static string $resource = ClientReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

