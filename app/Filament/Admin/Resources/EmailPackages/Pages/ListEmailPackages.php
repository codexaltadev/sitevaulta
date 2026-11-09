<?php

namespace App\Filament\Admin\Resources\EmailPackages\Pages;

use App\Filament\Admin\Resources\EmailPackages\EmailPackageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmailPackages extends ListRecords
{
    protected static string $resource = EmailPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

