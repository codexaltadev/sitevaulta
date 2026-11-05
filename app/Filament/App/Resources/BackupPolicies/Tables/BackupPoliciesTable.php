<?php

namespace App\Filament\App\Resources\BackupPolicies\Tables;

use App\Jobs\Backup\ExecuteBackupRunJob;
use App\Models\BackupPolicy;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BackupPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Policy Name')
                    ->searchable(),
                TextColumn::make('website.domain')
                    ->label('Website')
                    ->searchable(),
                TextColumn::make('scope')
                    ->badge(),
                TextColumn::make('strategy')
                    ->badge(),
                TextColumn::make('schedule_frequency')
                    ->label('Schedule')
                    ->badge(),
                TextColumn::make('retention_days')
                    ->label('Retention')
                    ->suffix(' days'),
                TextColumn::make('storageConnection.name')
                    ->label('Destination'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'warning'),
                TextColumn::make('next_run_at')
                    ->label('Next Scheduled Run')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('runBackupNow')
                    ->label('Run Backup Now')
                    ->icon(Heroicon::OutlinedPlay)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Execute On-Demand Backup Run')
                    ->modalDescription('This will immediately extract files/DB, compress, encrypt (if enabled), compute SHA-256, upload to your storage destination, and wipe temporary server files.')
                    ->action(function (BackupPolicy $record): void {
                        ExecuteBackupRunJob::dispatch($record->id, 'manual');

                        Notification::make()
                            ->title('⚡ Backup Dispatched in Background!')
                            ->body("Backup for \"{$record->website?->domain}\" is now executing asynchronously in the server background. You can freely close your browser or navigate anywhere — live percentage progress is updating on your Mission Control dashboard.")
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ]);

    }
}
