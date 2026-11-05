<?php

namespace App\Services\Billing;

use App\Enums\BillingCycle;
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

