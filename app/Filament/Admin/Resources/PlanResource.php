<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\PlanResource\Pages;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string | UnitEnum | null $navigationGroup = 'Billing & Revenue';

    protected static ?string $navigationLabel = 'Subscription Plans';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dynamic Plan Identity & Multi-Currency Pricing (With Yearly Package Discount %)')
                    ->description('Set monthly prices and your Yearly Package Discount Percentage (%). Yearly BDT and USD prices will automatically calculate.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('code')
                            ->label('Plan Code')
                            ->placeholder('e.g., starter, pro, agency')
                            ->required()
                            ->maxLength(50),

                        TextInput::make('name')
                            ->label('Display Name')
                            ->required()
                            ->maxLength(120),

                        TextInput::make('yearly_discount_percent')
                            ->label('Yearly Package Discount (%)')
                            ->helperText('e.g., 20 means 20% off on Yearly Billing (auto-calculates Yearly BDT & USD prices).')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(95)
                            ->step(0.01)
                            ->suffix('% OFF')
                            ->default(20)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                $discount = max(0.0, min(95.0, (float) $state));
                                $bdtMonthly = (float) $get('price_bdt_monthly');
                                $usdMonthly = (float) $get('price_usd_monthly');

                                if ($bdtMonthly > 0) {
                                    $set('price_bdt_yearly', round(($bdtMonthly * 12) * (1 - ($discount / 100)), 2));
                                }
                                if ($usdMonthly > 0) {
                                    $set('price_usd_yearly', round(($usdMonthly * 12) * (1 - ($discount / 100)), 2));
                                }
                            })
                            ->required(),

                        Textarea::make('description')
                            ->label('Plan Subtitle / Description')
                            ->rows(2)
                            ->columnSpanFull(),

                        TextInput::make('price_bdt_monthly')
                            ->label('Monthly Price (BDT ৳)')
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                $bdtMonthly = max(0.0, (float) $state);
                                $discount = max(0.0, min(95.0, (float) $get('yearly_discount_percent')));
                                if ($bdtMonthly > 0) {
                                    $set('price_bdt_yearly', round(($bdtMonthly * 12) * (1 - ($discount / 100)), 2));
                                }
                            })
                            ->required(),

                        TextInput::make('price_bdt_yearly')
                            ->label('Yearly Price After Discount (BDT ৳)')
                            ->helperText('Auto-calculated from 12 × Monthly BDT minus Yearly Discount %')
                            ->numeric()
                            ->default(0)
                            ->required(),

                        Placeholder::make('bdt_yearly_savings_preview')
                            ->label('BDT Yearly Savings Breakdown')
                            ->content(function (Get $get): HtmlString {
                                $monthly = (float) $get('price_bdt_monthly');
                                $discount = (float) $get('yearly_discount_percent');
                                $regular = round($monthly * 12, 2);
                                $yearly = $discount > 0 ? round($regular * (1 - ($discount / 100)), 2) : (float) $get('price_bdt_yearly');
                                $saved = max(0.0, round($regular - $yearly, 2));

                                return new HtmlString(
                                    "<div style='padding:10px 12px;border-radius:8px;background:var(--sv-bg);border:1px solid var(--sv-border);color:var(--sv-text);font-size:12.5px;line-height:1.5;'>" .
                                    "<div>12 Mo Regular: <strong>৳" . number_format($regular, 2) . "</strong></div>" .
                                    "<div style='color:var(--sv-primary);font-weight:700;'>Discount ({$discount}%): -৳" . number_format($saved, 2) . " &rarr; Pay ৳" . number_format($yearly, 2) . "</div>" .
                                    "</div>"
                                );
                            }),

                        TextInput::make('price_usd_monthly')
                            ->label('Monthly Price (USD $)')
                            ->numeric()
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                $usdMonthly = max(0.0, (float) $state);
                                $discount = max(0.0, min(95.0, (float) $get('yearly_discount_percent')));
                                if ($usdMonthly > 0) {
                                    $set('price_usd_yearly', round(($usdMonthly * 12) * (1 - ($discount / 100)), 2));
                                }
                            })
                            ->required(),

                        TextInput::make('price_usd_yearly')
                            ->label('Yearly Price After Discount (USD $)')
                            ->helperText('Auto-calculated from 12 × Monthly USD minus Yearly Discount %')
                            ->numeric()
                            ->default(0)
                            ->required(),

                        Placeholder::make('usd_yearly_savings_preview')
                            ->label('USD Yearly Savings Breakdown')
                            ->content(function (Get $get): HtmlString {
                                $monthly = (float) $get('price_usd_monthly');
                                $discount = (float) $get('yearly_discount_percent');
                                $regular = round($monthly * 12, 2);
                                $yearly = $discount > 0 ? round($regular * (1 - ($discount / 100)), 2) : (float) $get('price_usd_yearly');
                                $saved = max(0.0, round($regular - $yearly, 2));

                                return new HtmlString(
                                    "<div style='padding:10px 12px;border-radius:8px;background:var(--sv-bg);border:1px solid var(--sv-border);color:var(--sv-text);font-size:12.5px;line-height:1.5;'>" .
                                    "<div>12 Mo Regular: <strong>\$" . number_format($regular, 2) . "</strong></div>" .
                                    "<div style='color:var(--sv-primary);font-weight:700;'>Discount ({$discount}%): -\$" . number_format($saved, 2) . " &rarr; Pay \$" . number_format($yearly, 2) . "</div>" .
                                    "</div>"
                                );
                            }),
                    ]),

                Section::make('Dynamic Quota Limits & Feature Flags')
                    ->columns(3)
                    ->schema([
                        TextInput::make('max_websites')
                            ->label('Max Verified Websites')
                            ->numeric()
                            ->default(5)
                            ->required(),

                        TextInput::make('max_policies')
                            ->label('Max Backup Policies')
                            ->numeric()
                            ->default(10)
                            ->required(),

                        TextInput::make('max_storage_connections')
                            ->label('Max Storage Destinations')
                            ->numeric()
                            ->default(3)
                            ->required(),

                        TextInput::make('max_team_members')
                            ->label('Max Team Members')
                            ->numeric()
                            ->default(3)
                            ->required(),

                        TextInput::make('min_interval_minutes')
                            ->label('Min Schedule Interval (Minutes)')
                            ->numeric()
                            ->default(360)
                            ->required(),

                        TextInput::make('max_retention_days')
                            ->label('Max Retention Window (Days)')
                            ->numeric()
                            ->default(30)
                            ->required(),

                        TextInput::make('sort_order')
                            ->label('Display Sort Order')
                            ->numeric()
                            ->default(1)
                            ->required(),

                        Toggle::make('is_trial')
                            ->label('Is Free Trial Plan')
                            ->default(false),

                        TextInput::make('trial_days')
                            ->label('Trial Duration (Days)')
                            ->numeric()
                            ->default(7),

                        Toggle::make('is_featured')
                            ->label('Highlight as Popular Plan')
                            ->default(false),

                        Toggle::make('is_active')
                            ->label('Active on Public Pricing & Checkout')
                            ->default(true),

                        KeyValue::make('features')
                            ->label('Feature Entitlement Flags (JSON)')
                            ->keyLabel('Feature Key')
                            ->valueLabel('Enabled (true/false)')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
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
                    ->formatStateUsing(fn ($state): string => ((float) $state > 0) ? rtrim(rtrim(number_format((float) $state, 2), '0'), '.') . '% OFF' : '0%'),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
