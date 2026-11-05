<?php

namespace App\Filament\App\Widgets;

use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class RecentBackupStreamWidget extends Widget
{
    protected string $view = 'filament.app.widgets.recent-backup-stream-widget';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 4;

    protected static bool $isLazy = false;

    public function getPollingInterval(): ?string
    {
        /** @var Workspace|null $workspace */
        $workspace = Filament::getTenant();
        if (! $workspace) {
            return null;
        }

        $hasActive = $workspace->backupRuns()
            ->whereIn('status', [
                \App\Enums\BackupRunStatus::Queued,
                \App\Enums\BackupRunStatus::Connecting,
                \App\Enums\BackupRunStatus::Extracting,
                \App\Enums\BackupRunStatus::Packaging,
                \App\Enums\BackupRunStatus::Uploading,
                \App\Enums\BackupRunStatus::Verifying,
            ])
            ->exists();

        return $hasActive ? '3s' : '20s';
    }

    protected function getViewData(): array
    {
        /** @var Workspace|null $workspace */
        $workspace = Filament::getTenant();

        if (! $workspace) {
            return ['hasWorkspace' => false, 'runs' => collect()];
        }

        $runs = $workspace->backupRuns()
            ->with(['website', 'artifacts.storageConnection', 'backupPolicy'])
            ->latest('id')
            ->limit(6)
            ->get();

        return [
            'hasWorkspace' => true,
            'workspace' => $workspace,
            'runs' => $runs,
        ];
    }
}
