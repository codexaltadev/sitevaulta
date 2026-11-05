<?php

namespace App\Filament\App\Resources\BackupRuns\Pages;

use App\Filament\App\Resources\BackupRuns\BackupRunResource;
use Filament\Resources\Pages\ListRecords;

class ListBackupRuns extends ListRecords
{
    protected static string $resource = BackupRunResource::class;
}
