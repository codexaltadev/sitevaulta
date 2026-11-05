<?php

namespace App\Filament\Admin\Resources\Plans\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('code')
                    ->badge(),
                TextColumn::make('price_bdt_monthly')
                    ->label('BDT / Mo')
                    ->prefix('৳'),
                TextColumn::make('price_bdt_yearly')
                    ->label('BDT / Yr')
                    ->prefix('৳'),
                TextColumn::make('yearly_discount_percent')
                    ->label('Yearly Discount')
                    ->badge()
                    ->color(fn ($state): string => ((float) $state > 0) ? 'success' : 'gray')
                    ->formatStateUsing(fn ($state): string => ((float) $state > 0) ? rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'% OFF' : '0%'),
                TextColumn::make('price_usd_monthly')
                    ->label('USD / Mo')
                    ->prefix('$'),
                TextColumn::make('max_websites')
                    ->label('Max Sites'),
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
