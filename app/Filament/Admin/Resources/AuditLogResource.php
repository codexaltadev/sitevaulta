<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string | UnitEnum | null $navigationGroup = 'Operations & Monitoring';

    protected static ?string $navigationLabel = 'Immutable Audit Logs';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('event')
                    ->badge()
                    ->searchable(),
                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->placeholder('Global / System'),
                TextColumn::make('user.email')
                    ->label('Actor')
                    ->placeholder('System / Worker'),
                TextColumn::make('ip_address')
                    ->label('IP Address'),
                TextColumn::make('created_at')
                    ->dateTime(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}

