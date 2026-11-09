<?php

namespace App\Filament\Admin\Resources\EmailPackages;

use App\Filament\Admin\Resources\EmailPackages\Pages\CreateEmailPackage;
use App\Filament\Admin\Resources\EmailPackages\Pages\EditEmailPackage;
use App\Filament\Admin\Resources\EmailPackages\Pages\ListEmailPackages;
use App\Filament\Admin\Resources\EmailPackages\Schemas\EmailPackageForm;
use App\Filament\Admin\Resources\EmailPackages\Tables\EmailPackagesTable;
use App\Models\EmailPackage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class EmailPackageResource extends Resource
{
    protected static ?string $model = EmailPackage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & Revenue';

    protected static ?string $navigationLabel = 'Email Credit Packages';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return EmailPackageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmailPackagesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailPackages::route('/'),
            'create' => CreateEmailPackage::route('/create'),
            'edit' => EditEmailPackage::route('/{record}/edit'),
        ];
    }
}

