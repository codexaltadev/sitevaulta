<?php

namespace App\Filament\App\Resources\StatusPages\Pages;

use App\Filament\App\Resources\StatusPages\StatusPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStatusPages extends ListRecords
{
    protected static string $resource = StatusPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New status page'),
        ];
    }
}
