<?php

namespace App\Filament\App\Resources\StatusPages\Tables;

use App\Models\StatusPage;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class StatusPagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('No status pages yet')
            ->emptyStateDescription('Share a live status page with clients so they stop asking "is it down?".')
            ->columns([
                TextColumn::make('name')
                    ->weight('semibold')
                    ->searchable(),
                TextColumn::make('slug')
                    ->label('Public URL')
                    ->formatStateUsing(fn (StatusPage $record): string => $record->publicUrl())
                    ->url(fn (StatusPage $record): string => $record->publicUrl(), shouldOpenInNewTab: true)
                    ->copyable()
                    ->copyableState(fn (StatusPage $record): string => $record->publicUrl())
                    ->color('primary'),
                TextColumn::make('monitors_count')
                    ->label('Monitors')
                    ->counts('monitors'),
                IconColumn::make('password')
                    ->label('Password')
                    ->state(fn (StatusPage $record): bool => $record->isPasswordProtected())
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedLockClosed)
                    ->falseIcon(Heroicon::OutlinedLockOpen)
                    ->trueColor('warning')
                    ->falseColor('gray'),
                ToggleColumn::make('is_published')
                    ->label('Published'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->url(fn (StatusPage $record): string => $record->publicUrl(), shouldOpenInNewTab: true),
                Action::make('removePassword')
                    ->label('Remove password')
                    ->icon(Heroicon::OutlinedLockOpen)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (StatusPage $record): bool => $record->isPasswordProtected())
                    ->action(function (StatusPage $record): void {
                        $record->forceFill(['password' => null])->save();
                        Notification::make()->title('Status page is now public')->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
