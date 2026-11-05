<?php

namespace App\Filament\App\Resources\BackupPolicyResource\Pages;

use App\Enums\BackupScope;
use App\Enums\BackupStrategy;
use App\Filament\App\Resources\BackupPolicyResource;
use App\Models\StorageConnection;
use App\Models\Website;
use App\Services\Forecast\StorageForecastService;
use Filament\Resources\Pages\CreateRecord;

class CreateBackupPolicy extends CreateRecord
{
    protected static string $resource = BackupPolicyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
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
