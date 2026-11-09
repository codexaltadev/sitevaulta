<?php

namespace App\Filament\App\Resources\BackupPolicies\Tables;

use App\Jobs\Backup\ExecuteBackupRunJob;
use App\Models\BackupPolicy;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
                TextColumn::make('notification_email')
                    ->label('Run Alert Email')
                    ->placeholder('Not set')
                    ->description(fn (BackupPolicy $record): string => $record->notification_email
                        ? sprintf(
                            'Success: %s | Failed: %s',
                            $record->notify_on_success ? 'ON' : 'OFF',
                            $record->notify_on_failure ? 'ON' : 'OFF'
                        )
                        : 'Alerts disabled'),
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
                    ->modalHeading('Execute On-Demand Backup Run')
                    ->modalDescription('This will immediately extract files/DB, compress, encrypt (if enabled), compute SHA-256, upload to your storage destination, and wipe temporary server files.')
                    ->fillForm(fn (BackupPolicy $record): array => [
                        'notification_email' => $record->notification_email ?: auth()->user()?->email,
                        'notify_on_success' => (bool) ($record->notify_on_success ?? true),
                        'notify_on_failure' => (bool) ($record->notify_on_failure ?? true),
                    ])
                    ->form([
                        TextInput::make('notification_email')
                            ->label('Notification Email Address for This Run')
                            ->email()
                            ->placeholder('alerts@youragency.com')
                            ->helperText('Set the email address to receive alerts for this backup run.'),
                        Toggle::make('notify_on_success')
                            ->label('Send Email on Backup Success')
                            ->default(true)
                            ->onColor('success'),
                        Toggle::make('notify_on_failure')
                            ->label('Send Email on Backup Failed')
                            ->default(true)
                            ->onColor('danger'),
                    ])
                    ->action(function (BackupPolicy $record, array $data): void {
                        $email = isset($data['notification_email']) ? trim((string) $data['notification_email']) : $record->notification_email;
                        $notifyOnSuccess = (bool) ($data['notify_on_success'] ?? true);
                        $notifyOnFailure = (bool) ($data['notify_on_failure'] ?? true);

                        $record->update([
                            'notification_email' => $email ?: null,
                            'notify_on_success' => $notifyOnSuccess,
                            'notify_on_failure' => $notifyOnFailure,
                        ]);

                        ExecuteBackupRunJob::dispatch(
                            $record->id,
                            'manual',
                            null,
                            $email ?: null,
                            $notifyOnSuccess,
                            $notifyOnFailure
                        );

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
