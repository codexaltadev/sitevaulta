<?php

namespace App\Filament\App\Resources\MaintenanceWindows;

use App\Filament\App\Resources\MaintenanceWindows\Pages\CreateMaintenanceWindow;
use App\Filament\App\Resources\MaintenanceWindows\Pages\EditMaintenanceWindow;
use App\Filament\App\Resources\MaintenanceWindows\Pages\ListMaintenanceWindows;
use App\Filament\App\Resources\MaintenanceWindows\Schemas\MaintenanceWindowForm;
use App\Filament\App\Resources\MaintenanceWindows\Tables\MaintenanceWindowsTable;
use App\Models\MaintenanceWindow;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MaintenanceWindowResource extends Resource
{
    protected static ?string $model = MaintenanceWindow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Uptime Monitoring';

    protected static ?string $navigationLabel = 'Maintenance';

    protected static ?string $modelLabel = 'maintenance window';

    protected static ?string $slug = 'maintenance';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return MaintenanceWindowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaintenanceWindowsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceWindows::route('/'),
            'create' => CreateMaintenanceWindow::route('/create'),
            'edit' => EditMaintenanceWindow::route('/{record}/edit'),
        ];
    }
}
