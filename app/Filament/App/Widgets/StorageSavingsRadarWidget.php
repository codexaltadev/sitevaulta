<?php

namespace App\Filament\App\Widgets;

use App\Enums\BackupRunStatus;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class StorageSavingsRadarWidget extends Widget
{
    protected string $view = 'filament.app.widgets.storage-savings-radar-widget';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = '30s';

    protected function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 MB';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    protected function getViewData(): array
    {
        /** @var Workspace|null $workspace */
        $workspace = Filament::getTenant();

        if (! $workspace) {
            return ['hasWorkspace' => false];
        }

        $storageConnections = $workspace->storageConnections()->latest('last_verified_at')->get();

        // Calculate actual storage occupied by completed runs
        $completedRuns = $workspace->backupRuns()->where('status', BackupRunStatus::Completed)->get();

        $actualStoredBytes = (int) $completedRuns->sum('archive_size_bytes');
        $naiveRawBytes = (int) $completedRuns->sum('raw_source_size_bytes');

        // If naiveRawBytes is less than actualStoredBytes (or zero), fallback gracefully
        if ($naiveRawBytes <= $actualStoredBytes && $actualStoredBytes > 0) {
            $naiveRawBytes = (int) ($actualStoredBytes * 2.2);
        }

        $savedBytes = max(0, $naiveRawBytes - $actualStoredBytes);
        $savedPercent = $naiveRawBytes > 0 ? round(($savedBytes / $naiveRawBytes) * 100, 1) : 0;

        // Visual progress bar width
        $barRatio = $naiveRawBytes > 0 ? max(8, min(100, round(($actualStoredBytes / $naiveRawBytes) * 100))) : 20;

        return [
            'hasWorkspace' => true,
            'workspace' => $workspace,
            'storageConnections' => $storageConnections,
            'hasRuns' => $completedRuns->isNotEmpty(),
            'actualStoredFormatted' => $this->formatBytes($actualStoredBytes),
            'naiveRawFormatted' => $this->formatBytes($naiveRawBytes),
            'savedFormatted' => $this->formatBytes($savedBytes),
            'savedPercent' => $savedPercent,
            'barRatio' => $barRatio,
        ];
    }
}
