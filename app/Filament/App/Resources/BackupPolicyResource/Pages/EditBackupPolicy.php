<?php

namespace App\Filament\App\Resources\BackupPolicyResource\Pages;

use App\Filament\App\Resources\BackupPolicyResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBackupPolicy extends EditRecord
{
    protected static string $resource = BackupPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

