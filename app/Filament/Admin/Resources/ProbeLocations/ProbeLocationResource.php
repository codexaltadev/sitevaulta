<?php

namespace App\Filament\Admin\Resources\ProbeLocations;

use App\Filament\Admin\Resources\ProbeLocations\Pages\CreateProbeLocation;
use App\Filament\Admin\Resources\ProbeLocations\Pages\EditProbeLocation;
use App\Filament\Admin\Resources\ProbeLocations\Pages\ListProbeLocations;
use App\Filament\Admin\Resources\ProbeLocations\Schemas\ProbeLocationForm;
use App\Filament\Admin\Resources\ProbeLocations\Tables\ProbeLocationsTable;
use App\Models\ProbeLocation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProbeLocationResource extends Resource
{
    protected static ?string $model = ProbeLocation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAsiaAustralia;

    protected static string|UnitEnum|null $navigationGroup = 'Operations & Monitoring';

    protected static ?string $navigationLabel = 'Probe locations';

    protected static ?string $modelLabel = 'probe location';

    public static function form(Schema $schema): Schema
    {
        return ProbeLocationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProbeLocationsTable::configure($table);
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
            'index' => ListProbeLocations::route('/'),
            'create' => CreateProbeLocation::route('/create'),
            'edit' => EditProbeLocation::route('/{record}/edit'),
        ];
    }
}
