<?php

namespace App\Services\Billing;

use App\Enums\BillingCycle;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Coupon;
use App\Models\EmailPackage;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\Security\AuditLogService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MultiGatewayBillingService
{
    public static function calculateCheckoutPricing(
        Plan $plan,
        BillingCycle $cycle,
        string $currency = 'BDT',
        ?string $couponCode = null
    ): array {
        $currency = strtoupper($currency) === 'USD' ? 'USD' : 'BDT';

        $monthlyAmount = $currency === 'USD' ? (float) $plan->price_usd_monthly : (float) $plan->price_bdt_monthly;
        $regularAmount = $cycle === BillingCycle::Yearly ? $plan->getRegularAnnualPrice($currency) : $monthlyAmount;
        $yearlyDiscountPercent = $cycle === BillingCycle::Yearly ? $plan->getYearlyDiscountPercent() : 0.0;

        $baseAmount = match ($cycle) {
            BillingCycle::Yearly => $plan->getYearlyPrice($currency),
            default => $monthlyAmount,
        };

        $yearlyDiscountAmount = $cycle === BillingCycle::Yearly
            ? max(0.0, round($regularAmount - $baseAmount, 2))
            : 0.0;

        $discountAmount = 0.0;
        $appliedCoupon = null;

        if (! empty($couponCode)) {
            $coupon = Coupon::where('code', strtoupper(trim($couponCode)))->first();
            if ($coupon && $coupon->isValid()) {
                $appliedCoupon = $coupon;
                if ($coupon->discount_type === 'percentage') {
                    $discountAmount = round($baseAmount * (((float) $coupon->discount_value) / 100), 2);
                } elseif ($coupon->discount_type === 'fixed_bdt' && $currency === 'BDT') {
                    $discountAmount = min($baseAmount, (float) $coupon->discount_value);
                } elseif ($coupon->discount_type === 'fixed_usd' && $currency === 'USD') {
                    $discountAmount = min($baseAmount, (float) $coupon->discount_value);
                }
            }
        }

        $subTotal = max(0.0, $baseAmount - $discountAmount);
        $taxPercent = (float) SystemSettingsService::get('trial_billing', 'tax_percentage', 0.0);
        $taxAmount = round($subTotal * ($taxPercent / 100), 2);
        $totalAmount = round($subTotal + $taxAmount, 2);

        return [
            'currency' => $currency,
            'regular_amount' => $regularAmount,
            'yearly_discount_percent' => $yearlyDiscountPercent,
            'yearly_discount_amount' => $yearlyDiscountAmount,
            'base_amount' => $baseAmount,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'coupon' => $appliedCoupon,
        ];
    }

    /**
     * Processes a verified gateway payment idempotently and activates/renews the Workspace Subscription.
     */
    public static function fulfillSuccessfulPayment(
        Workspace $workspace,
        Plan $plan,
        BillingCycle $cycle,
        PaymentGateway $gateway,
        string $gatewayTransactionId,
        string $idempotencyKey,
        string $currency,
        float $amount,
        float $discountAmount = 0.0,
        float $taxAmount = 0.0,
        array $gatewayPayload = [],
        ?Coupon $coupon = null
    ): Payment {
        return DB::transaction(function () use (
            $workspace,
            $plan,
            $cycle,
            $gateway,
            $gatewayTransactionId,
            $idempotencyKey,
            $currency,
            $amount,
            $discountAmount,
            $taxAmount,
            $gatewayPayload,
            $coupon
        ): Payment {
            // 1. Idempotency Guard: if this event/transaction was already processed, return existing payment
            $existing = Payment::where('idempotency_key', $idempotencyKey)
                ->orWhere(function ($q) use ($gatewayTransactionId) {
                    if ($gatewayTransactionId !== '') {
                        $q->where('gateway_transaction_id', $gatewayTransactionId);
                    }
                })
                ->first();

            if ($existing && $existing->status === PaymentStatus::Succeeded) {
                return $existing;
            }

            $now = now()->utc();
            $subscription = $workspace->currentSubscription;

            // SaaS Subscription Management:
            // If renewing/extending the SAME active plan, extend the period from the current expiry date
            // so the user does NOT lose any remaining paid days!
            $isSameActivePlan = $subscription
                && $subscription->plan_id === $plan->id
                && $subscription->status === SubscriptionStatus::Active
                && $subscription->current_period_ends_at
                && $subscription->current_period_ends_at->isFuture();

            $baseDate = $isSameActivePlan ? $subscription->current_period_ends_at->copy() : $now->copy();
            $startsAt = $isSameActivePlan ? ($subscription->current_period_starts_at ?? $now) : $now;

            $periodEnd = match ($cycle) {
                BillingCycle::Yearly => $baseDate->addYear(),
                BillingCycle::Lifetime => $baseDate->addYears(50),
                default => $baseDate->addMonth(),
            };

            if ($subscription) {
                $subscription->update([
                    'plan_id' => $plan->id,
                    'billing_cycle' => $cycle,
                    'status' => SubscriptionStatus::Active,
                    'gateway' => $gateway,
                    'gateway_subscription_id' => $gatewayTransactionId,
                    'current_period_starts_at' => $startsAt,
                    'current_period_ends_at' => $periodEnd,
                    'grace_ends_at' => null,
                ]);
            } else {
                $subscription = Subscription::create([
                    'workspace_id' => $workspace->id,
                    'plan_id' => $plan->id,
                    'billing_cycle' => $cycle,
                    'status' => SubscriptionStatus::Active,
                    'gateway' => $gateway,
                    'gateway_subscription_id' => $gatewayTransactionId,
                    'current_period_starts_at' => $startsAt,
                    'current_period_ends_at' => $periodEnd,
                ]);
            }

            // Re-activate any policies that were paused due to trial/subscription expiry
            $workspace->backupPolicies()
                ->where('status', 'inactive_entitlement')
                ->update([
                    'status' => 'active',
                    'next_run_at' => $now->copy()->addMinutes(5),
                ]);

            if ($coupon) {
                $coupon->increment('redeemed_count');
            }

            $invoiceNumber = $existing?->invoice_number ?: ('INV-' . $now->format('Ymd') . '-' . strtoupper(Str::random(6)));

            $payment = Payment::updateOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'workspace_id' => $workspace->id,
                    'subscription_id' => $subscription->id,
                    'invoice_number' => $invoiceNumber,
                    'gateway' => $gateway,
                    'gateway_transaction_id' => $gatewayTransactionId,
                    'currency' => $currency,
                    'amount' => $amount,
                    'discount_amount' => $discountAmount,
                    'tax_amount' => $taxAmount,
                    'status' => PaymentStatus::Succeeded,
                    'gateway_payload' => $gatewayPayload,
                    'paid_at' => $now,
                ]
            );

            AuditLogService::log(
                event: 'subscription.upgraded',
                auditable: $subscription,
                metadata: [
                    'plan' => $plan->code,
                    'cycle' => $cycle->value,
                    'gateway' => $gateway->value,
                    'invoice' => $invoiceNumber,
                    'amount' => $amount,
                    'currency' => $currency,
                ],
                workspaceId: $workspace->id
            );

            // Process Referral Purchase Reward if this workspace was referred!
            EmailWalletService::processReferralRewardOnPurchase(
                purchasingWorkspace: $workspace,
                payment: $payment,
                purchasedItemLabel: "{$plan->name} Subscription ({$cycle->label()})"
            );

            return $payment;
        });
    }

    /**
     * Processes a verified gateway payment for an Email Credit Package idempotently,
     * deposits the credits into the Workspace's Digital Email Wallet, and triggers referral rewards if eligible.
     */
    public static function fulfillEmailPackagePayment(
        Workspace $workspace,
        EmailPackage $emailPackage,
        PaymentGateway $gateway,
        string $gatewayTransactionId,
        string $idempotencyKey,
        string $currency,
        float $amount,
        float $taxAmount = 0.0,
        array $gatewayPayload = []
    ): Payment {
        return DB::transaction(function () use (
            $workspace,
            $emailPackage,
            $gateway,
            $gatewayTransactionId,
            $idempotencyKey,
            $currency,
            $amount,
            $taxAmount,
            $gatewayPayload
        ): Payment {
            $existing = Payment::where('idempotency_key', $idempotencyKey)
                ->orWhere(function ($q) use ($gatewayTransactionId) {
                    if ($gatewayTransactionId !== '') {
                        $q->where('gateway_transaction_id', $gatewayTransactionId);
                    }
                })
                ->first();

            if ($existing && $existing->status === PaymentStatus::Succeeded) {
                return $existing;
            }

            $now = now()->utc();
            $invoiceNumber = $existing?->invoice_number ?: ('INV-' . $now->format('Ymd') . '-' . strtoupper(Str::random(6)));
            $totalCredits = $emailPackage->totalCredits();

            $payment = Payment::updateOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'workspace_id' => $workspace->id,
                    'subscription_id' => $workspace->currentSubscription?->id,
                    'invoice_number' => $invoiceNumber,
                    'gateway' => $gateway,
                    'gateway_transaction_id' => $gatewayTransactionId,
                    'currency' => $currency,
                    'amount' => $amount,
                    'discount_amount' => 0,
                    'tax_amount' => $taxAmount,
                    'status' => PaymentStatus::Succeeded,
                    'gateway_payload' => array_merge($gatewayPayload, [
                        'purchase_type' => 'email_package',
                        'email_package_id' => $emailPackage->id,
                        'email_package_code' => $emailPackage->code,
                        'email_package_name' => $emailPackage->name,
                        'credits_added' => $totalCredits,
                    ]),
                    'paid_at' => $now,
                ]
            );

            EmailWalletService::creditWallet(
                workspace: $workspace,
                credits: $totalCredits,
                type: 'package_purchase',
                description: "Purchased {$emailPackage->name} (+{$totalCredits} Email Credits) — Invoice {$invoiceNumber}",
                reference: $payment,
                metadata: [
                    'email_package_code' => $emailPackage->code,
                    'credits_amount' => $emailPackage->credits_amount,
                    'bonus_credits' => $emailPackage->bonus_credits,
                    'invoice_number' => $invoiceNumber,
                ]
            );

            AuditLogService::log(
                event: 'email_wallet.package_purchased',
                auditable: $payment,
                metadata: [
                    'package' => $emailPackage->code,
                    'credits_added' => $totalCredits,
                    'invoice' => $invoiceNumber,
                    'amount' => $amount,
                    'currency' => $currency,
                ],
                workspaceId: $workspace->id
            );

            EmailWalletService::processReferralRewardOnPurchase(
                purchasingWorkspace: $workspace,
                payment: $payment,
                purchasedItemLabel: $emailPackage->name
            );

            return $payment;
        });
    }
}

