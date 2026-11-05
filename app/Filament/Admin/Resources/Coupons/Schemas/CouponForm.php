<?php

namespace App\Filament\Admin\Resources\Coupons\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Promotional Discount Coupon')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Coupon Code')
                            ->placeholder('e.g., LAUNCH20')
                            ->required()
                            ->maxLength(50),

                        Select::make('discount_type')
                            ->options([
                                'percentage' => 'Percentage (%)',
                                'fixed_bdt' => 'Fixed Amount (BDT ৳)',
                                'fixed_usd' => 'Fixed Amount (USD $)',
                            ])
                            ->default('percentage')
                            ->required(),

                        TextInput::make('discount_value')
                            ->numeric()
                            ->default(20)
                            ->required(),

                        TextInput::make('max_redemptions')
                            ->label('Max Redemptions (Blank = Unlimited)')
                            ->numeric(),

                        DateTimePicker::make('expires_at')
                            ->label('Expires At'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ]);

    }
}
