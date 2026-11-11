<?php

namespace App\Filament\App\Resources\StatusPages;

use App\Filament\App\Resources\StatusPages\Pages\CreateStatusPage;
use App\Filament\App\Resources\StatusPages\Pages\EditStatusPage;
use App\Filament\App\Resources\StatusPages\Pages\ListStatusPages;
use App\Filament\App\Resources\StatusPages\RelationManagers\AnnouncementsRelationManager;
use App\Filament\App\Resources\StatusPages\RelationManagers\SubscribersRelationManager;
use App\Filament\App\Resources\StatusPages\Schemas\StatusPageForm;
use App\Filament\App\Resources\StatusPages\Tables\StatusPagesTable;
use App\Models\StatusPage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class StatusPageResource extends Resource
{
    protected static ?string $model = StatusPage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Uptime Monitoring';

    protected static ?string $navigationLabel = 'Status pages';

    protected static ?string $modelLabel = 'status page';

    protected static ?string $slug = 'status-pages';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return StatusPageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StatusPagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AnnouncementsRelationManager::class,
            SubscribersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStatusPages::route('/'),
            'create' => CreateStatusPage::route('/create'),
            'edit' => EditStatusPage::route('/{record}/edit'),
        ];
    }
}
