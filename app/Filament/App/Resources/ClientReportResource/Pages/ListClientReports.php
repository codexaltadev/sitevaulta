<?php

namespace App\Filament\App\Resources\ClientReportResource\Pages;

use App\Filament\App\Resources\ClientReportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClientReports extends ListRecords
{
    protected static string $resource = ClientReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

