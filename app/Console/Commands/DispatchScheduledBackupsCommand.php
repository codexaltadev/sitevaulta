<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Jobs\Backup\ExecuteBackupRunJob;
use App\Jobs\Backup\PruneExpiredBackupsJob;
use App\Models\BackupPolicy;
use App\Models\Subscription;
use App\Services\Billing\EntitlementService;
use Illuminate\Console\Command;

class DispatchScheduledBackupsCommand extends Command
{
    protected $signature = 'sitevaulta:dispatch-scheduled-backups {--sync : Execute jobs synchronously}';

    protected $description = 'Enforce 7-day trial expirations, dispatch due scheduled backups, and run chain-safe retention pruning.';

    public function handle(): int
    {
        $now = now()->utc();

        // 1. Enforce 7-Day Free Trial Expirations
        $expiredTrials = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now)
            ->get();

        foreach ($expiredTrials as $subscription) {
            if ($subscription->workspace) {
                EntitlementService::checkAndExpireSubscription($subscription->workspace);
            }
        }

        // 2. Dispatch Due Active Backup Policies
        $duePolicies = BackupPolicy::query()
            ->with(['workspace', 'website'])
            ->where('status', 'active')
            ->where(function ($q) use ($now) {
                $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            })
            ->get();

        $dispatchedCount = 0;

        foreach ($duePolicies as $policy) {
            if (! EntitlementService::canExecutePolicy($policy)) {
                continue;
            }

            if ($this->option('sync')) {
                ExecuteBackupRunJob::dispatchSync($policy->id, 'scheduled');
                PruneExpiredBackupsJob::dispatchSync();
            } else {
                ExecuteBackupRunJob::dispatch($policy->id, 'scheduled');
                PruneExpiredBackupsJob::dispatch();
            }

            $dispatchedCount++;
        }

        $this->info("Expired trials enforced: {$expiredTrials->count()} | Dispatched backup policies: {$dispatchedCount}");

        return self::SUCCESS;
    }
}
