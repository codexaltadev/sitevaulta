<?php

namespace Tests\Feature;

use App\Enums\BillingCycle;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\MultiGatewayBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPeriodExtensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_model_helpers_return_correct_validity_and_expiry(): void
    {
        $this->seed();

        $workspace = Workspace::where('slug', 'demo-agency')->first();
        $plan = Plan::where('code', 'pro')->first();

        // 1. Active subscription expiring in 20 days
        $futureDate = now()->utc()->addDays(20);
        $sub = Subscription::create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'current_period_starts_at' => now()->utc()->subDays(10),
            'current_period_ends_at' => $futureDate,
        ]);

        $this->assertEquals($futureDate->toDateTimeString(), $sub->getExpiryDate()?->toDateTimeString());
        $this->assertEquals(20, $sub->getRemainingDays());
        $this->assertFalse($sub->isExpiringSoon(7));
        $this->assertStringContainsString('Valid until', $sub->getValidityDescription());
        $this->assertStringContainsString('20 days remaining', $sub->getValidityDescription());

        // 2. Expiring soon within 5 days
        $sub->update(['current_period_ends_at' => now()->utc()->addDays(4)]);
        $this->assertTrue($sub->fresh()->isExpiringSoon(7));

        // 3. Trialing subscription
        $sub->update([
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->utc()->addDays(6),
        ]);
        $this->assertEquals(6, $sub->fresh()->getRemainingDays());
        $this->assertStringContainsString('Free Trial ends', $sub->fresh()->getValidityDescription());
    }

    public function test_paying_for_same_active_plan_extends_period_from_current_expiry(): void
    {
        $this->seed();

        $workspace = Workspace::where('slug', 'demo-agency')->first();
        $proPlan = Plan::where('code', 'pro')->first();

        // Set existing active subscription with 15 days remaining
        $initialExpiry = now()->utc()->addDays(15);
        $subscription = Subscription::create([
            'workspace_id' => $workspace->id,
            'plan_id' => $proPlan->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'current_period_starts_at' => now()->utc()->subDays(15),
            'current_period_ends_at' => $initialExpiry,
        ]);

        // Fulfill a monthly payment for the SAME plan
        $service = app(MultiGatewayBillingService::class);
        $payment = $service->fulfillSuccessfulPayment(
            $workspace,
            $proPlan,
            BillingCycle::Monthly,
            PaymentGateway::SslCommerz,
            'TX-EXTEND-12345',
            'idemp_extend_1',
            'BDT',
            (float) $proPlan->price_bdt_monthly
        );

        $this->assertEquals(PaymentStatus::Succeeded, $payment->status);

        $updatedSub = $subscription->fresh();
        // The new expiry date should be initialExpiry + 1 month (approx 45 days from now), NOT now() + 1 month!
        $expectedExpiry = $initialExpiry->copy()->addMonth();

        $this->assertEquals(
            $expectedExpiry->format('Y-m-d H:i'),
            $updatedSub->current_period_ends_at->format('Y-m-d H:i'),
            'Renewing the same plan must extend current_period_ends_at from the existing future expiry date!'
        );
    }

    public function test_upgrading_to_a_different_plan_resets_period_from_now(): void
    {
        $this->seed();

        $workspace = Workspace::where('slug', 'demo-agency')->first();
        $starterPlan = Plan::where('code', 'starter')->first();
        $proPlan = Plan::where('code', 'pro')->first();

        // Initial subscription is starter
        $subscription = Subscription::create([
            'workspace_id' => $workspace->id,
            'plan_id' => $starterPlan->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'current_period_starts_at' => now()->utc()->subDays(10),
            'current_period_ends_at' => now()->utc()->addDays(20),
        ]);

        // Upgrade to Pro plan
        $service = app(MultiGatewayBillingService::class);
        $payment = $service->fulfillSuccessfulPayment(
            $workspace,
            $proPlan,
            BillingCycle::Monthly,
            PaymentGateway::Stripe,
            'TX-UPGRADE-999',
            'idemp_upgrade_1',
            'USD',
            (float) $proPlan->price_usd_monthly
        );

        $this->assertEquals(PaymentStatus::Succeeded, $payment->status);

        $updatedSub = $subscription->fresh();
        $this->assertEquals($proPlan->id, $updatedSub->plan_id);

        // Should start fresh from now + 1 month
        $expectedExpiry = now()->utc()->addMonth();
        $this->assertEquals(
            $expectedExpiry->format('Y-m-d H:i'),
            $updatedSub->current_period_ends_at->format('Y-m-d H:i'),
            'Upgrading to a different plan must start immediately from now()!'
        );
    }

    public function test_billing_page_renders_validity_description(): void
    {
        $this->seed();

        $user = User::where('email', 'demo@sitevaulta.com')->first();
        $workspace = Workspace::where('slug', 'demo-agency')->first();
        $plan = Plan::where('code', 'pro')->first();

        $expiry = now()->utc()->addDays(25);
        Subscription::updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => BillingCycle::Monthly,
                'current_period_starts_at' => now()->utc()->subDays(5),
                'current_period_ends_at' => $expiry,
            ]
        );

        $response = $this->actingAs($user)
            ->get('/app/' . $workspace->slug . '/billing');

        $response->assertStatus(200)
            ->assertSee('Subscription Validity')
            ->assertSee($expiry->format('M d, Y'))
            ->assertSee('25 days remaining');
    }

    public function test_can_renew_same_plan_respects_renewal_window(): void
    {
        $this->seed();

        $workspace = Workspace::where('slug', 'demo-agency')->first();
        $plan = Plan::where('code', 'pro')->first();

        // 1. Subscription with 20 days remaining: should NOT be eligible to renew same plan (window = 7 days)
        $sub = Subscription::create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_cycle' => BillingCycle::Monthly,
            'current_period_starts_at' => now()->utc()->subDays(10),
            'current_period_ends_at' => now()->utc()->addDays(20),
        ]);

        $this->assertFalse($sub->canRenewSamePlan(7));

        // 2. Subscription with 5 days remaining: SHOULD be eligible to renew same plan
        $sub->update(['current_period_ends_at' => now()->utc()->addDays(5)]);
        $this->assertTrue($sub->fresh()->canRenewSamePlan(7));

        // 3. Expired or Trialing: ALWAYS eligible
        $sub->update(['status' => SubscriptionStatus::Trialing]);
        $this->assertTrue($sub->fresh()->canRenewSamePlan(7));
    }

    public function test_billing_page_shows_renewal_locked_notice_when_outside_window(): void
    {
        $this->seed();

        $user = User::where('email', 'demo@sitevaulta.com')->first();
        $workspace = Workspace::where('slug', 'demo-agency')->first();
        $plan = Plan::where('code', 'pro')->first();

        $expiry = now()->utc()->addDays(20);
        Subscription::updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'billing_cycle' => BillingCycle::Monthly,
                'current_period_starts_at' => now()->utc()->subDays(10),
                'current_period_ends_at' => $expiry,
            ]
        );

        $response = $this->actingAs($user)
            ->get('/app/' . $workspace->slug . '/billing');

        $response->assertStatus(200)
            ->assertSee('Active Subscription in Good Standing')
            ->assertSee('renewal unlocks');
    }

    public function test_gateway_initiation_methods_handle_stripe_and_paypal_credentials(): void
    {
        $this->seed();

        $workspace = Workspace::where('slug', 'demo-agency')->first();
        $plan = Plan::where('code', 'pro')->first();

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'invoice_number' => 'INV-TEST-STRIPE-1',
            'gateway' => PaymentGateway::Stripe,
            'gateway_transaction_id' => 'TX-STRIPE-1',
            'idempotency_key' => 'idemp-stripe-1',
            'currency' => 'USD',
            'amount' => 29.00,
            'status' => PaymentStatus::Pending,
        ]);

        // When unconfigured or demo key, Stripe returns null safely
        $url = \App\Http\Controllers\BillingCheckoutController::initiateStripeGatewayUrl($payment, $plan, BillingCycle::Monthly);
        $this->assertNull($url);

        // Test PayPal OAuth and Order creation using Http::fake
        \Illuminate\Support\Facades\Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => \Illuminate\Support\Facades\Http::response([
                'access_token' => 'mock_access_token_123',
            ], 200),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => \Illuminate\Support\Facades\Http::response([
                'id' => 'PAYPAL-ORDER-999',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-999'],
                ],
            ], 201),
        ]);

        \App\Services\Settings\SystemSettingsService::set('payment_paypal', 'client_id', 'real_paypal_client_id_test', 'string', false, true);
        \App\Services\Settings\SystemSettingsService::set('payment_paypal', 'client_secret', 'real_paypal_client_secret_test', 'string', true, false);
        \App\Services\Settings\SystemSettingsService::set('payment_paypal', 'sandbox_mode', true, 'boolean', false, false);

        $paypalPayment = Payment::create([
            'workspace_id' => $workspace->id,
            'invoice_number' => 'INV-TEST-PAYPAL-1',
            'gateway' => PaymentGateway::PayPal,
            'gateway_transaction_id' => 'TX-PAYPAL-1',
            'idempotency_key' => 'idemp-paypal-1',
            'currency' => 'USD',
            'amount' => 29.00,
            'status' => PaymentStatus::Pending,
        ]);

        $paypalUrl = \App\Http\Controllers\BillingCheckoutController::initiatePayPalGatewayUrl($paypalPayment, $plan, BillingCycle::Monthly);
        $this->assertEquals('https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-999', $paypalUrl);

        // When visiting showCheckout, it redirects to the PayPal gateway URL
        $response = $this->get('/billing/checkout/' . $paypalPayment->invoice_number);
        $response->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-ORDER-999');
    }
}
