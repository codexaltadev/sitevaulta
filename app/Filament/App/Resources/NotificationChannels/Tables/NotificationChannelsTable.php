<?php

namespace App\Filament\App\Resources\NotificationChannels\Tables;

use App\Models\NotificationChannel;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotificationChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('sendTestAlert')
                    ->label('Send Test Alert')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('info')
                    ->action(function (NotificationChannel $record): void {
                        Notification::make()
                            ->title("Test alert dispatched to [{$record->name}] ({$record->type->label()}).")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);

    }
}
