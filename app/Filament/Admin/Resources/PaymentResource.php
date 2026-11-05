<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\PaymentResource\Pages;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string | UnitEnum | null $navigationGroup = 'Billing & Revenue';

    protected static ?string $navigationLabel = 'Payments & Invoices';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->searchable(),
                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->searchable(),
                TextColumn::make('gateway')
                    ->badge(),
                TextColumn::make('gateway_transaction_id')
                    ->label('Gateway Tx ID')
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, Payment $record): string => "{$state} {$record->currency}"),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('paid_at')
                    ->dateTime(),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('viewInvoice')
                    ->label('View Invoice')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Payment $record): string => route('billing.invoice.view', ['paymentUuid' => $record->invoice_number]), shouldOpenInNewTab: true),

                \Filament\Actions\Action::make('downloadInvoicePdf')
                    ->label('Invoice PDF')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('info')
                    ->url(fn (Payment $record): string => route('billing.invoice.pdf', ['paymentUuid' => $record->invoice_number]), shouldOpenInNewTab: true),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
        ];
    }
}

