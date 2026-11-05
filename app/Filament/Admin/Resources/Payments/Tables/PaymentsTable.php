<?php

namespace App\Filament\Admin\Resources\Payments\Tables;

use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
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
                Action::make('viewInvoice')
                    ->label('View Invoice')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Payment $record): string => route('billing.invoice.view', ['paymentUuid' => $record->invoice_number]), shouldOpenInNewTab: true),

                Action::make('downloadInvoicePdf')
                    ->label('Invoice PDF')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('info')
                    ->url(fn (Payment $record): string => route('billing.invoice.pdf', ['paymentUuid' => $record->invoice_number]), shouldOpenInNewTab: true),
            ]);

    }
}
