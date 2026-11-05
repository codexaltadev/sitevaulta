<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\PaymentGateway;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    protected $fillable = [
        'workspace_id',
        'plan_id',
        'billing_cycle',
        'status',
        'gateway',
        'gateway_subscription_id',
        'trial_starts_at',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'grace_ends_at',
        'canceled_at',
        'custom_quota_overrides',
    ];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'status' => SubscriptionStatus::class,
            'gateway' => PaymentGateway::class,
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
            'custom_quota_overrides' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function effectiveLimit(string $key): int
    {
        $override = data_get($this->custom_quota_overrides, $key);
        if ($override !== null && $override !== '') {
            return (int) $override;
        }

        return (int) ($this->plan?->{$key} ?? 0);
    }

    public function getExpiryDate(): ?\Illuminate\Support\Carbon
    {
        if ($this->status === SubscriptionStatus::Trialing) {
            return $this->trial_ends_at;
        }

        if ($this->status === SubscriptionStatus::PastDue && $this->grace_ends_at) {
            return $this->grace_ends_at;
        }

        return $this->current_period_ends_at;
    }

    public function getRemainingDays(): int
    {
        $expiry = $this->getExpiryDate();
        if (! $expiry) {
            return 0;
        }

        $now = now()->utc();
        if ($now->greaterThanOrEqualTo($expiry)) {
            return 0;
        }

        return max(0, (int) ceil($now->floatDiffInDays($expiry, false)));
    }

    public function isExpiringSoon(int $thresholdDays = 7): bool
    {
        if (in_array($this->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Expired])) {
            return true;
        }

        $expiry = $this->getExpiryDate();
        if (! $expiry) {
            return false;
        }

        return $expiry->isFuture() && now()->utc()->diffInDays($expiry) <= $thresholdDays;
    }

    public function canRenewSamePlan(int $windowDays = 7): bool
    {
        if ($this->status !== SubscriptionStatus::Active) {
            return true;
        }

        $expiry = $this->getExpiryDate();
        if (! $expiry) {
            return true;
        }

        return $this->getRemainingDays() <= $windowDays;
    }

    public function getValidityDescription(): string
    {
        $expiry = $this->getExpiryDate();
        if (! $expiry) {
            return 'No expiration date recorded';
        }

        if ($this->status === SubscriptionStatus::Expired) {
            return 'Expired on ' . $expiry->format('M d, Y') . ' (' . $expiry->diffForHumans() . ')';
        }

        if ($this->status === SubscriptionStatus::PastDue) {
            return 'Payment Past Due — Grace period ends on ' . $expiry->format('M d, Y') . ' (' . $expiry->diffForHumans() . ')';
        }

        if ($this->status === SubscriptionStatus::Trialing) {
            $days = $this->getRemainingDays();

            return "Free Trial ends on {$expiry->format('M d, Y')} ({$days} day" . ($days === 1 ? '' : 's') . ' remaining)';
        }

        $days = $this->getRemainingDays();

        return "Valid until {$expiry->format('M d, Y')} ({$days} day" . ($days === 1 ? '' : 's') . ' remaining)';
    }
}


