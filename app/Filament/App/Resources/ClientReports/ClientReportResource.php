<?php

namespace App\Filament\App\Resources\ClientReports;

use App\Filament\App\Resources\ClientReports\Pages\CreateClientReport;
use App\Filament\App\Resources\ClientReports\Pages\EditClientReport;
use App\Filament\App\Resources\ClientReports\Pages\ListClientReports;
use App\Filament\App\Resources\ClientReports\Schemas\ClientReportForm;
use App\Filament\App\Resources\ClientReports\Tables\ClientReportsTable;
use App\Models\ClientReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ClientReportResource extends Resource
{
    protected static ?string $model = ClientReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Alerts & Agency Reports';

    protected static ?string $navigationLabel = 'White-Label Client Reports';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return ClientReportForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientReportsTable::configure($table);
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
            'index' => ListClientReports::route('/'),
            'create' => CreateClientReport::route('/create'),
            'edit' => EditClientReport::route('/{record}/edit'),
        ];
    }
}
