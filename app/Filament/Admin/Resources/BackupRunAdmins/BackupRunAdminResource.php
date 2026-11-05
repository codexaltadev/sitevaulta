<?php

namespace App\Filament\Admin\Resources\BackupRunAdmins;

use App\Filament\Admin\Resources\BackupRunAdmins\Pages\ListBackupRuns;
use App\Filament\Admin\Resources\BackupRunAdmins\Tables\BackupRunAdminsTable;
use App\Models\BackupRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BackupRunAdminResource extends Resource
{
    protected static ?string $model = BackupRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Operations & Monitoring';

    protected static ?string $navigationLabel = 'Global Backup Runs';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return BackupRunAdminsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBackupRuns::route('/'),
        ];
    }
}
