<?php

namespace App\Filament\App\Widgets;

use App\Enums\BackupRunStatus;
use App\Enums\WebsiteVerificationStatus;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\Workspace;
use Database\Seeders\DemoWorkspaceSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

class WorkspaceHealthPulseWidget extends Widget
{
    protected string $view = 'filament.app.widgets.workspace-health-pulse-widget';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 1;

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
                BackupRunStatus::Queued,
                BackupRunStatus::Connecting,
                BackupRunStatus::Extracting,
                BackupRunStatus::Packaging,
                BackupRunStatus::Uploading,
                BackupRunStatus::Verifying,
            ])
            ->exists();

        return $hasActive ? '3s' : '15s';
    }

    public function loadDemoData(): void
    {
        /** @var Workspace|null $workspace */
        $workspace = Filament::getTenant();
        if (! $workspace) {
            return;
        }

        app(DemoWorkspaceSeeder::class)->seedDemoForWorkspace($workspace);

        Notification::make()
            ->title('Demo Data Loaded Successfully')
            ->body('Realistic websites, cloud storage destinations, and backup run chains have been generated.')
            ->success()
            ->send();
    }

    protected function getViewData(): array
    {
        /** @var Workspace|null $workspace */
        $workspace = Filament::getTenant();

        if (! $workspace) {
            return ['hasWorkspace' => false];
        }

        $websites = $workspace->websites;
        $totalWebsites = $websites->count();
        $verifiedWebsites = $websites->where('verification_status', WebsiteVerificationStatus::Verified)->count();

        // Check if there are active policies
        $activePoliciesCount = $workspace->backupPolicies()->where('status', 'active')->count();

        // Check if any backup is actively running / streaming right now
        $activeRun = $workspace->backupRuns()
            ->whereIn('status', [
                BackupRunStatus::Queued,
                BackupRunStatus::Connecting,
                BackupRunStatus::Extracting,
                BackupRunStatus::Packaging,
                BackupRunStatus::Uploading,
                BackupRunStatus::Verifying,
            ])
            ->with(['website', 'backupPolicy'])
            ->latest('id')
            ->first();

        // Latest completed backup run
        $latestCompletedRun = $workspace->backupRuns()
            ->where('status', BackupRunStatus::Completed)
            ->with(['website', 'artifacts.storageConnection'])
            ->latest('completed_at')
            ->first();

        // Next upcoming scheduled backup
        $nextPolicy = $workspace->backupPolicies()
            ->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '>', now())
            ->orderBy('next_run_at')
            ->with('website')
            ->first();

        // Failure count in last 7 days
        $recentFailures = $workspace->backupRuns()
            ->where('status', BackupRunStatus::Failed)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        $subscription = $workspace->currentSubscription()->with('plan')->first();

        return [
            'hasWorkspace' => true,
            'workspace' => $workspace,
            'subscription' => $subscription,
            'totalWebsites' => $totalWebsites,
            'verifiedWebsites' => $verifiedWebsites,
            'activePoliciesCount' => $activePoliciesCount,
            'activeRun' => $activeRun,
            'latestCompletedRun' => $latestCompletedRun,
            'nextPolicy' => $nextPolicy,
            'recentFailures' => $recentFailures,
            'isEmptyState' => ($totalWebsites === 0),
        ];
    }
}
