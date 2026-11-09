<?php

namespace App\Filament\Admin\Resources\Workspaces\Tables;

use App\Models\Workspace;
use App\Services\Billing\EmailWalletService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkspacesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->badge(),
                TextColumn::make('owner.email')
                    ->label('Owner'),
                TextColumn::make('currentSubscription.plan.name')
                    ->label('Plan')
                    ->badge(),
                TextColumn::make('currentSubscription.status')
                    ->label('Sub Status')
                    ->badge(),
                TextColumn::make('email_credits_balance')
                    ->label('Email Credits')
                    ->badge()
                    ->color('success')
                    ->suffix(' credits'),
                TextColumn::make('websites_count')
                    ->counts('websites')
                    ->label('Sites'),
                IconColumn::make('is_suspended')
                    ->label('Suspended')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('adjustEmailCredits')
                    ->label('Adjust Email Credits')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->color('success')
                    ->modalHeading(fn (Workspace $record): string => "Adjust Email Wallet Credits — {$record->name}")
                    ->form([
                        TextInput::make('credits_delta')
                            ->label('Credits to Add or Deduct (e.g., 500 or -100)')
                            ->numeric()
                            ->default(500)
                            ->required(),
                        TextInput::make('reason')
                            ->label('Adjustment Note')
                            ->default('Admin bonus email credits')
                            ->required(),
                    ])
                    ->action(function (Workspace $record, array $data): void {
                        $delta = (int) ($data['credits_delta'] ?? 0);
                        $reason = trim((string) ($data['reason'] ?? 'Admin credit adjustment'));
                        if ($delta !== 0) {
                            EmailWalletService::creditWallet(
                                workspace: $record,
                                credits: $delta,
                                type: 'admin_adjustment',
                                description: "Admin Adjustment: {$reason}"
                            );
                            Notification::make()
                                ->title("Updated Email Wallet by {$delta} credits.")
                                ->success()
                                ->send();
                        }
                    }),

                Action::make('extendTrial7Days')
                    ->label('+7d Trial')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('info')
                    ->action(function (Workspace $record): void {
                        $sub = $record->currentSubscription;
                        if ($sub) {
                            $base = $sub->trial_ends_at && $sub->trial_ends_at->isFuture() ? $sub->trial_ends_at : now();
                            $sub->update([
                                'trial_ends_at' => $base->copy()->addDays(7),
                                'current_period_ends_at' => $base->copy()->addDays(7),
                            ]);
                            Notification::make()->title('Extended trial by 7 days.')->success()->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);

    }
}
