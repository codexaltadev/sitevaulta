<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CouponResource\Pages;
use App\Models\Coupon;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string | UnitEnum | null $navigationGroup = 'Billing & Revenue';

    protected static ?string $navigationLabel = 'Promo Coupons';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
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

    public static function table(Table $table): Table
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoupons::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }
}

