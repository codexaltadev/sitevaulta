<?php

namespace App\Filament\Admin\Resources\EmailPackages\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmailPackagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Package Name')
                    ->searchable(),
                TextColumn::make('code')
                    ->badge(),
                TextColumn::make('credits_amount')
                    ->label('Base Credits')
                    ->numeric(),
                TextColumn::make('bonus_credits')
                    ->label('Bonus Credits')
                    ->numeric(),
                TextColumn::make('price_bdt')
                    ->label('BDT Price')
                    ->prefix('৳'),
                TextColumn::make('price_usd')
                    ->label('USD Price')
                    ->prefix('$'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}

