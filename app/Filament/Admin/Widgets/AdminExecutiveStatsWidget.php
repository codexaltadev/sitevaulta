<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\BackupRunStatus;
use App\Enums\PaymentStatus;
use App\Enums\WebsiteVerificationStatus;
use App\Models\BackupRun;
use App\Models\Payment;
use App\Models\Website;
use App\Models\Workspace;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminExecutiveStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalWorkspaces = Workspace::count();
        $verifiedWebsites = Website::where('verification_status', WebsiteVerificationStatus::Verified)->count();
        $bdtRevenue = (float) Payment::where('status', PaymentStatus::Succeeded)->where('currency', 'BDT')->sum('amount');
        $usdRevenue = (float) Payment::where('status', PaymentStatus::Succeeded)->where('currency', 'USD')->sum('amount');
        $completedRuns = BackupRun::where('status', BackupRunStatus::Completed)->count();

        return [
            Stat::make('Customer Workspaces', (string) $totalWorkspaces)
                ->description('Active multi-tenant workspaces')
                ->color('primary'),

            Stat::make('Verified Websites', (string) $verifiedWebsites)
                ->description('Ownership-verified domains')
                ->color('success'),

            Stat::make('Global Backup Runs', (string) $completedRuns)
                ->description('SHA-256 verified archives')
                ->color('info'),

            Stat::make('Collected Revenue', '৳' . number_format($bdtRevenue, 0) . ' / $' . number_format($usdRevenue, 0))
                ->description('SSLCOMMERZ (BDT) + Stripe/PayPal (USD)')
                ->color('warning'),
        ];
    }
}

