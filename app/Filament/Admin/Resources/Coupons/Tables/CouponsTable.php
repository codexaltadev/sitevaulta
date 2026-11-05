<?php

namespace App\Filament\Admin\Resources\Coupons\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->badge()
                    ->searchable(),
                TextColumn::make('discount_type')
                    ->badge(),
                TextColumn::make('discount_value'),
                TextColumn::make('redeemed_count')
                    ->label('Redemptions'),
                TextColumn::make('expires_at')
                    ->dateTime(),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);

    }
}
