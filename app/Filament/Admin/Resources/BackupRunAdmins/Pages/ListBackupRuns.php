<?php

namespace App\Filament\Admin\Resources\BackupRunAdmins\Pages;

use App\Filament\Admin\Resources\BackupRunAdmins\BackupRunAdminResource;
use Filament\Resources\Pages\ListRecords;

class ListBackupRuns extends ListRecords
{
    protected static string $resource = BackupRunAdminResource::class;
}
