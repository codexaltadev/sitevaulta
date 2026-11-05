<?php

namespace App\Filament\App\Resources\StorageConnections;

use App\Filament\App\Resources\StorageConnections\Pages\CreateStorageConnection;
use App\Filament\App\Resources\StorageConnections\Pages\EditStorageConnection;
use App\Filament\App\Resources\StorageConnections\Pages\ListStorageConnections;
use App\Filament\App\Resources\StorageConnections\Schemas\StorageConnectionForm;
use App\Filament\App\Resources\StorageConnections\Tables\StorageConnectionsTable;
use App\Models\StorageConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class StorageConnectionResource extends Resource
{
    protected static ?string $model = StorageConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowUp;

    protected static string|UnitEnum|null $navigationGroup = 'Backup Management';

    protected static ?string $navigationLabel = '3. Storage Connections (BYOS)';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return StorageConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StorageConnectionsTable::configure($table);
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
            'index' => ListStorageConnections::route('/'),
            'create' => CreateStorageConnection::route('/create'),
            'edit' => EditStorageConnection::route('/{record}/edit'),
        ];
    }
}
