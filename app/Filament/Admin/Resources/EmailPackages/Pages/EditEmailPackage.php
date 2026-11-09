<?php

namespace App\Filament\Admin\Resources\EmailPackages\Pages;

use App\Filament\Admin\Resources\EmailPackages\EmailPackageResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmailPackage extends EditRecord
{
    protected static string $resource = EmailPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

