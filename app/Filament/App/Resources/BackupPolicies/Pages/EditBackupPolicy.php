<?php

namespace App\Filament\App\Resources\BackupPolicies\Pages;

use App\Enums\BackupScope;
use App\Enums\BackupStrategy;
use App\Filament\App\Resources\BackupPolicies\BackupPolicyResource;
use App\Jobs\Backup\ExecuteBackupRunJob;
use App\Models\BackupPolicy;
use App\Models\StorageConnection;
use App\Models\Website;
use App\Services\Forecast\StorageForecastService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditBackupPolicy extends EditRecord
{
    protected static string $resource = BackupPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runBackupNow')
                ->label('Run Backup Now')
                ->icon(Heroicon::OutlinedPlay)
                ->color('success')
                ->modalHeading('Execute On-Demand Backup Run')
                ->modalDescription('Configure or confirm the email notification settings for this run and execute the backup immediately in the background.')
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
                        ->helperText('Leave blank if you do not want email alerts for this run.'),
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
                        ->body("Backup for \"{$record->website?->domain}\" is now executing asynchronously in the server background.")
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $website = isset($data['website_id']) ? Website::find($data['website_id']) : null;
        $storageConn = isset($data['storage_connection_id']) ? StorageConnection::find($data['storage_connection_id']) : null;

        $data['forecast_snapshot'] = StorageForecastService::calculate(
            website: $website,
            scope: BackupScope::tryFrom((string) ($data['scope'] ?? 'full_stack')) ?? BackupScope::FullStack,
            strategy: BackupStrategy::tryFrom((string) ($data['strategy'] ?? 'incremental_with_periodic_full')) ?? BackupStrategy::IncrementalWithPeriodicFull,
            intervalMinutes: max(15, (int) ($data['interval_minutes'] ?? 1440)),
            retentionDays: max(1, (int) ($data['retention_days'] ?? 14)),
            fullResetEveryN: max(1, (int) ($data['full_reset_every_n'] ?? 7)),
            storage: $storageConn,
        );

        return $data;
    }
}
