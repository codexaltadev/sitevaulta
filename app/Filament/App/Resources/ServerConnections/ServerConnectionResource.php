<?php

namespace App\Filament\App\Resources\ServerConnections;

use App\Filament\App\Resources\ServerConnections\Pages\CreateServerConnection;
use App\Filament\App\Resources\ServerConnections\Pages\EditServerConnection;
use App\Filament\App\Resources\ServerConnections\Pages\ListServerConnections;
use App\Filament\App\Resources\ServerConnections\Schemas\ServerConnectionForm;
use App\Filament\App\Resources\ServerConnections\Tables\ServerConnectionsTable;
use App\Models\ServerConnection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ServerConnectionResource extends Resource
{
    protected static ?string $model = ServerConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Backup Management';

    protected static ?string $navigationLabel = '2. Server & DB Connectors';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ServerConnectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServerConnectionsTable::configure($table);
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
            'index' => ListServerConnections::route('/'),
            'create' => CreateServerConnection::route('/create'),
            'edit' => EditServerConnection::route('/{record}/edit'),
        ];
    }
}
