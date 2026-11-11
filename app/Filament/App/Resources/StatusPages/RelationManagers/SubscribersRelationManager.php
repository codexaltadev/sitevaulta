<?php

namespace App\Filament\App\Resources\StatusPages\RelationManagers;

use App\Models\StatusPageSubscriber;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * People who subscribed on the public page. They add themselves (with email confirmation); the owner can
 * only see and remove them — never add addresses, so nobody gets mail they did not ask for.
 */
class SubscribersRelationManager extends RelationManager
{
    protected static string $relationship = 'subscribers';

    protected static ?string $title = 'Subscribers';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No subscribers yet')
            ->emptyStateDescription('Visitors can subscribe at the bottom of the public status page.')
            ->columns([
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('confirmed_at')
                    ->label('State')
                    ->badge()
                    ->state(fn (StatusPageSubscriber $record): string => $record->confirmed_at ? 'Confirmed' : 'Waiting for confirmation')
                    ->color(fn (StatusPageSubscriber $record): string => $record->confirmed_at ? 'success' : 'warning'),
                TextColumn::make('created_at')
                    ->label('Subscribed')
                    ->since(),
            ])
            ->filters([
                TernaryFilter::make('confirmed_at')
                    ->label('Confirmed')
                    ->nullable(),
            ])
            ->recordActions([
                DeleteAction::make()->label('Remove'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }
}
