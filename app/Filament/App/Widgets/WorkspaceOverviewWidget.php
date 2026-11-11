<?php

namespace App\Filament\App\Widgets;

use App\Enums\BackupRunStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class WorkspaceOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        /** @var Workspace|null $workspace */
        $workspace = Filament::getTenant();
        if (! $workspace) {
            return [];
        }

        $totalWebsites = $workspace->websites()->count();
        $verifiedWebsites = $workspace->websites()->ownershipVerified()->count();
        $activePolicies = $workspace->backupPolicies()->where('status', 'active')->count();
        $completedRuns = $workspace->backupRuns()->where('status', BackupRunStatus::Completed)->count();
        $sub = $workspace->currentSubscription()->with('plan')->first();

        $planLabel = $sub?->plan?->name ?? 'Unassigned';
        $validityDesc = $sub ? $sub->getValidityDescription() : 'No active subscription';
        $subColor = match ($sub?->status) {
            SubscriptionStatus::Active => $sub->isExpiringSoon(7) ? 'warning' : 'success',
            SubscriptionStatus::Trialing => 'info',
            SubscriptionStatus::PastDue => 'warning',
            default => 'danger',
        };

        return [
            Stat::make('Verified Websites', "{$verifiedWebsites} / {$totalWebsites}")
                ->description('Domain ownership verified')
                ->color('success'),

            Stat::make('Active Backup Policies', (string) $activePolicies)
                ->description('Automated schedules running')
                ->color('primary'),

            Stat::make('Verified Backup Runs', (string) $completedRuns)
                ->description('SHA-256 verified recovery points')
                ->color('info'),

            Stat::make('Current Plan', $planLabel)
                ->description($validityDesc)
                ->color($subColor),
        ];
    }
}
