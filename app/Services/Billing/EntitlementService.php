<?php

namespace App\Services\Billing;

use App\Enums\BillingCycle;
use App\Enums\MonitorType;
use App\Enums\PaymentGateway;
use App\Enums\SubscriptionStatus;
use App\Models\BackupPolicy;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Settings\SystemSettingsService;

class EntitlementService
{
    public static function provisionFreeTrial(Workspace $workspace): Subscription
    {
        $trialPlan = Plan::where('is_trial', true)->first()
            ?? Plan::where('code', 'free')->first();

        $trialDays = (int) SystemSettingsService::get(
            'trial_billing',
            'default_trial_days',
            $trialPlan?->trial_days ?? 7
        );

        return Subscription::create([
            'workspace_id' => $workspace->id,
            'plan_id' => $trialPlan->id,
            'billing_cycle' => BillingCycle::Trial,
            'status' => SubscriptionStatus::Trialing,
            'gateway' => PaymentGateway::None,
            'trial_starts_at' => now()->utc(),
            'trial_ends_at' => now()->utc()->addDays($trialDays),
        ]);
    }

    public static function checkAndExpireSubscription(Workspace $workspace): ?Subscription
    {
        $sub = $workspace->currentSubscription;
        if (! $sub) {
            return null;
        }

        if ($sub->status === SubscriptionStatus::Trialing && $sub->trial_ends_at && $sub->trial_ends_at->isPast()) {
            $sub->update(['status' => SubscriptionStatus::Expired]);
            $workspace->backupPolicies()
                ->where('status', 'active')
                ->update(['status' => 'inactive_entitlement']);
        } elseif ($sub->status === SubscriptionStatus::Active && $sub->current_period_ends_at && $sub->current_period_ends_at->isPast()) {
            $graceDays = (int) SystemSettingsService::get('trial_billing', 'grace_period_days', 3);
            $sub->update([
                'status' => SubscriptionStatus::PastDue,
                'grace_ends_at' => $sub->current_period_ends_at->copy()->addDays($graceDays),
            ]);
        } elseif ($sub->status === SubscriptionStatus::PastDue && $sub->grace_ends_at && $sub->grace_ends_at->isPast()) {
            $sub->update(['status' => SubscriptionStatus::Expired]);
            $workspace->backupPolicies()
                ->where('status', 'active')
                ->update(['status' => 'inactive_entitlement']);
        }

        return $sub->fresh();
    }

    public static function canAddWebsite(Workspace $workspace): bool
    {
        if (! $workspace->hasActiveEntitlement()) {
            return false;
        }

        $sub = $workspace->currentSubscription;
        $max = $sub ? $sub->effectiveLimit('max_websites') : 1;

        return $workspace->websites()->count() < $max;
    }

    public static function monitorLimit(Workspace $workspace): int
    {
        $sub = $workspace->currentSubscription;

        return $sub ? $sub->effectiveLimit('max_monitors') : 10;
    }

    /**
     * Monitor types the workspace's plan allows (plans.allowed_monitor_types; empty = every type).
     *
     * @return list<string>
     */
    public static function allowedMonitorTypes(Workspace $workspace): array
    {
        $configured = $workspace->currentSubscription?->plan?->allowed_monitor_types;

        return filled($configured)
            ? array_values(array_intersect(array_column(MonitorType::cases(), 'value'), $configured))
            : array_column(MonitorType::cases(), 'value');
    }

    public static function minMonitorIntervalSeconds(Workspace $workspace): int
    {
        $sub = $workspace->currentSubscription;

        return max(60, $sub ? $sub->effectiveLimit('min_monitor_interval_seconds') : 300);
    }

    public static function remainingMonitorSlots(Workspace $workspace): int
    {
        if (! $workspace->hasActiveEntitlement()) {
            return 0;
        }

        return max(0, self::monitorLimit($workspace) - $workspace->monitors()->count());
    }

    public static function canAddMonitor(Workspace $workspace): bool
    {
        return self::remainingMonitorSlots($workspace) > 0;
    }

    public static function statusPageLimit(Workspace $workspace): int
    {
        $sub = $workspace->currentSubscription;

        return $sub ? $sub->effectiveLimit('max_status_pages') : 1;
    }

    public static function canAddStatusPage(Workspace $workspace): bool
    {
        if (! $workspace->hasActiveEntitlement()) {
            return false;
        }

        return $workspace->statusPages()->count() < self::statusPageLimit($workspace);
    }

    public static function canAddPolicy(Workspace $workspace): bool
    {
        if (! $workspace->hasActiveEntitlement()) {
            return false;
        }

        $sub = $workspace->currentSubscription;
        $max = $sub ? $sub->effectiveLimit('max_policies') : 1;

        return $workspace->backupPolicies()->where('status', 'active')->count() < $max;
    }

    public static function canAddStorageConnection(Workspace $workspace): bool
    {
        if (! $workspace->hasActiveEntitlement()) {
            return false;
        }

        $sub = $workspace->currentSubscription;
        $max = $sub ? $sub->effectiveLimit('max_storage_connections') : 1;

        return $workspace->storageConnections()->count() < $max;
    }

    public static function canExecutePolicy(BackupPolicy $policy): bool
    {
        $workspace = $policy->workspace;
        if (! $workspace) {
            return false;
        }

        self::checkAndExpireSubscription($workspace);

        if (! $workspace->fresh()->hasActiveEntitlement()) {
            return false;
        }

        if (! $policy->website || ! $policy->website->isVerified()) {
            return false;
        }

        return $policy->status === 'active';
    }
}
