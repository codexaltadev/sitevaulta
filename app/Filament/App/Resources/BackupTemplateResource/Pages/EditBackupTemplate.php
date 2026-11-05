<?php

namespace App\Filament\App\Resources\BackupTemplateResource\Pages;

use App\Filament\App\Resources\BackupTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBackupTemplate extends EditRecord
{
    protected static string $resource = BackupTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

