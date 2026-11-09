<?php

namespace App\Filament\Admin\Resources\EmailPackages\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmailPackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('System Email Credit Package (Digital Wallet Top-Up)')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Package Code')
                            ->placeholder('e.g., email_growth_2500')
                            ->required()
                            ->maxLength(50),

                        TextInput::make('name')
                            ->label('Package Name')
                            ->placeholder('e.g., Growth 2,500 Email Pack (+250 Bonus)')
                            ->required()
                            ->maxLength(120),

                        TextInput::make('description')
                            ->label('Short Description')
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('credits_amount')
                            ->label('Base Email Credits')
                            ->numeric()
                            ->minValue(1)
                            ->default(500)
                            ->required(),

                        TextInput::make('bonus_credits')
                            ->label('Bonus Free Credits')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),

                        TextInput::make('price_bdt')
                            ->label('Price in BDT (৳)')
                            ->numeric()
                            ->step(0.01)
                            ->default(190.00)
                            ->required(),

                        TextInput::make('price_usd')
                            ->label('Price in USD ($)')
                            ->numeric()
                            ->step(0.01)
                            ->default(2.00)
                            ->required(),

                        TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->default(1),

                        Toggle::make('is_featured')
                            ->label('Featured Package')
                            ->default(false),

                        Toggle::make('is_active')
                            ->label('Active (Available for Purchase)')
                            ->default(true),
                    ]),
            ]);
    }
}

