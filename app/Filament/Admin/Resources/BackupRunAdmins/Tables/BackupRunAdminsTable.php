<?php

namespace App\Filament\Admin\Resources\BackupRunAdmins\Tables;

use App\Enums\BackupRunStatus;
use App\Jobs\Backup\ExecuteBackupRunJob;
use App\Models\BackupRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BackupRunAdminsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Run #')
                    ->sortable(),
                TextColumn::make('trigger_type')
                    ->label('Trigger')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'manual' => '⚡ Manual',
                        'scheduled' => '⏰ Scheduled (Auto)',
                        default => ucfirst($state ?? 'Auto'),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'manual' => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->searchable(),
                TextColumn::make('website.domain')
                    ->label('Domain')
                    ->searchable(),
                TextColumn::make('backup_type')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (BackupRunStatus $state): string => $state->color()),
                TextColumn::make('archive_size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (?int $state, BackupRun $record): string => $record->formattedSize()),
                IconColumn::make('is_anomaly')
                    ->label('Anomaly')
                    ->boolean(),
                TextColumn::make('completed_at')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('retryBackup')
                    ->label('Retry Run')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn (BackupRun $record): bool => $record->backup_policy_id !== null)
                    ->action(function (BackupRun $record): void {
                        ExecuteBackupRunJob::dispatch($record->backup_policy_id, 'admin_retry');
                        Notification::make()
                            ->title("Dispatched retry backup run in background for {$record->website?->domain}.")
                            ->success()
                            ->send();
                    }),
            ]);

    }
}
