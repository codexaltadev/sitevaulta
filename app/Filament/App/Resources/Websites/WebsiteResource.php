<?php

namespace App\Filament\App\Resources\Websites;

use App\Filament\App\Resources\Websites\Pages\CreateWebsite;
use App\Filament\App\Resources\Websites\Pages\EditWebsite;
use App\Filament\App\Resources\Websites\Pages\ListWebsites;
use App\Filament\App\Resources\Websites\Schemas\WebsiteForm;
use App\Filament\App\Resources\Websites\Tables\WebsitesTable;
use App\Models\Website;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class WebsiteResource extends Resource
{
    protected static ?string $model = Website::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Backup Management';

    protected static ?string $navigationLabel = '1. Websites & Verification';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return WebsiteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WebsitesTable::configure($table);
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
            'index' => ListWebsites::route('/'),
            'create' => CreateWebsite::route('/create'),
            'edit' => EditWebsite::route('/{record}/edit'),
        ];
    }
}
