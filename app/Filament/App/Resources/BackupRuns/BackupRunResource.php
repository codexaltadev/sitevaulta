<?php

namespace App\Filament\App\Resources\BackupRuns;

use App\Filament\App\Resources\BackupRuns\Pages\ListBackupRuns;
use App\Filament\App\Resources\BackupRuns\Tables\BackupRunsTable;
use App\Models\BackupRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BackupRunResource extends Resource
{
    protected static ?string $model = BackupRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Backup Management';

    protected static ?string $navigationLabel = '6. Backup Runs & Restore';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return BackupRunsTable::configure($table);
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
