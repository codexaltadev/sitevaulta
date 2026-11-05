<?php

namespace App\Filament\App\Resources\BackupPolicies;

use App\Filament\App\Resources\BackupPolicies\Pages\CreateBackupPolicy;
use App\Filament\App\Resources\BackupPolicies\Pages\EditBackupPolicy;
use App\Filament\App\Resources\BackupPolicies\Pages\ListBackupPolicies;
use App\Filament\App\Resources\BackupPolicies\Schemas\BackupPolicyForm;
use App\Filament\App\Resources\BackupPolicies\Tables\BackupPoliciesTable;
use App\Models\BackupPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BackupPolicyResource extends Resource
{
    protected static ?string $model = BackupPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Backup Management';

    protected static ?string $navigationLabel = '5. Backup Policies & Schedules';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return BackupPolicyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BackupPoliciesTable::configure($table);
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
            'index' => ListBackupPolicies::route('/'),
            'create' => CreateBackupPolicy::route('/create'),
            'edit' => EditBackupPolicy::route('/{record}/edit'),
        ];
    }
}
