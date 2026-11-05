<?php

namespace App\Filament\App\Resources\NotificationChannels;

use App\Filament\App\Resources\NotificationChannels\Pages\CreateNotificationChannel;
use App\Filament\App\Resources\NotificationChannels\Pages\EditNotificationChannel;
use App\Filament\App\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Filament\App\Resources\NotificationChannels\Schemas\NotificationChannelForm;
use App\Filament\App\Resources\NotificationChannels\Tables\NotificationChannelsTable;
use App\Models\NotificationChannel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class NotificationChannelResource extends Resource
{
    protected static ?string $model = NotificationChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Alerts & Agency Reports';

    protected static ?string $navigationLabel = 'Alert Channels';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return NotificationChannelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NotificationChannelsTable::configure($table);
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
            'index' => ListNotificationChannels::route('/'),
            'create' => CreateNotificationChannel::route('/create'),
            'edit' => EditNotificationChannel::route('/{record}/edit'),
        ];
    }
}
