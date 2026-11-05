<?php

namespace App\Filament\App\Resources\BackupTemplates;

use App\Filament\App\Resources\BackupTemplates\Pages\CreateBackupTemplate;
use App\Filament\App\Resources\BackupTemplates\Pages\EditBackupTemplate;
use App\Filament\App\Resources\BackupTemplates\Pages\ListBackupTemplates;
use App\Filament\App\Resources\BackupTemplates\Schemas\BackupTemplateForm;
use App\Filament\App\Resources\BackupTemplates\Tables\BackupTemplatesTable;
use App\Models\BackupTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BackupTemplateResource extends Resource
{
    protected static ?string $model = BackupTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Backup Management';

    protected static ?string $navigationLabel = '4. Policy Templates';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return BackupTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BackupTemplatesTable::configure($table);
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
            'index' => ListBackupTemplates::route('/'),
            'create' => CreateBackupTemplate::route('/create'),
            'edit' => EditBackupTemplate::route('/{record}/edit'),
        ];
    }
}
