<?php

namespace App\Filament\App\Resources\BackupPolicyResource\Pages;

use App\Filament\App\Resources\BackupPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBackupPolicies extends ListRecords
{
    protected static string $resource = BackupPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

