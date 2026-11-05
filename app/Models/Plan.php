<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'sort_order',
        'is_trial',
        'trial_days',
        'price_bdt_monthly',
        'price_bdt_yearly',
        'price_usd_monthly',
        'price_usd_yearly',
        'yearly_discount_percent',
        'max_websites',
        'max_policies',
        'max_storage_connections',
        'max_team_members',
        'min_interval_minutes',
        'max_retention_days',
        'features',
        'is_featured',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_trial' => 'boolean',
            'trial_days' => 'integer',
            'price_bdt_monthly' => 'decimal:2',
            'price_bdt_yearly' => 'decimal:2',
            'price_usd_monthly' => 'decimal:2',
            'price_usd_yearly' => 'decimal:2',
            'yearly_discount_percent' => 'decimal:2',
            'max_websites' => 'integer',
            'max_policies' => 'integer',
            'max_storage_connections' => 'integer',
            'max_team_members' => 'integer',
            'min_interval_minutes' => 'integer',
            'max_retention_days' => 'integer',
            'features' => 'array',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $plan): void {
            $discount = max(0.0, min(95.0, (float) ($plan->yearly_discount_percent ?? 0)));

            if ($discount > 0) {
                $bdtMonthly = (float) $plan->price_bdt_monthly;
                $usdMonthly = (float) $plan->price_usd_monthly;

                if ($bdtMonthly > 0 && ($plan->isDirty('yearly_discount_percent') || $plan->isDirty('price_bdt_monthly') || (float) $plan->price_bdt_yearly <= 0)) {
                    $plan->price_bdt_yearly = round(($bdtMonthly * 12) * (1 - ($discount / 100)), 2);
                }

                if ($usdMonthly > 0 && ($plan->isDirty('yearly_discount_percent') || $plan->isDirty('price_usd_monthly') || (float) $plan->price_usd_yearly <= 0)) {
                    $plan->price_usd_yearly = round(($usdMonthly * 12) * (1 - ($discount / 100)), 2);
                }
            }
        });
    }

    public function getYearlyDiscountPercent(): float
    {
        $configured = (float) ($this->yearly_discount_percent ?? 0);
        if ($configured > 0) {
            return round($configured, 2);
        }

        $regularAnnualBdt = (float) $this->price_bdt_monthly * 12;
        $yearlyBdt = (float) $this->price_bdt_yearly;
        if ($regularAnnualBdt > 0 && $yearlyBdt > 0 && $yearlyBdt < $regularAnnualBdt) {
            return round(((1 - ($yearlyBdt / $regularAnnualBdt)) * 100), 2);
        }

        return 0.0;
    }

    public function getRegularAnnualPrice(string $currency = 'BDT'): float
    {
        $monthly = strtoupper($currency) === 'USD'
            ? (float) $this->price_usd_monthly
            : (float) $this->price_bdt_monthly;

        return round($monthly * 12, 2);
    }

    public function getYearlyPrice(string $currency = 'BDT'): float
    {
        $discount = (float) ($this->yearly_discount_percent ?? 0);
        $regularAnnual = $this->getRegularAnnualPrice($currency);

        if ($discount > 0 && $regularAnnual > 0) {
            return round($regularAnnual * (1 - ($discount / 100)), 2);
        }

        return strtoupper($currency) === 'USD'
            ? (float) $this->price_usd_yearly
            : (float) $this->price_bdt_yearly;
    }

    public function getYearlySavingsAmount(string $currency = 'BDT'): float
    {
        $regular = $this->getRegularAnnualPrice($currency);
        $yearly = $this->getYearlyPrice($currency);

        return max(0.0, round($regular - $yearly, 2));
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function hasFeature(string $featureKey): bool
    {
        return (bool) data_get($this->features, $featureKey, false);
    }
}
