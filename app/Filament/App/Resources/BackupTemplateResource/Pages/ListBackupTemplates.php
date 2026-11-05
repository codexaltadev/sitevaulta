<?php

namespace App\Filament\App\Resources\BackupTemplateResource\Pages;

use App\Filament\App\Resources\BackupTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBackupTemplates extends ListRecords
{
    protected static string $resource = BackupTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

