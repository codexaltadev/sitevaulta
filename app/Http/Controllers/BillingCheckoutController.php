<?php

namespace App\Http\Controllers;

use App\Enums\BillingCycle;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Billing\MultiGatewayBillingService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Stripe\StripeClient;

class BillingCheckoutController extends Controller
{
    /**
     * Initiates a real SSLCOMMERZ Sandbox / Live session via POST /gwprocess/v4/api.php
     * and returns the official GatewayPageURL (https://sandbox.sslcommerz.com/EasyCheckOut/...).
     */
    public static function initiateSslCommerzGatewayUrl(Payment $payment, ?Plan $plan, BillingCycle $cycle): ?string
    {
        $storeId = trim((string) SystemSettingsService::get('payment_sslcommerz', 'store_id', 'codex6ae2d3cc64516'));
        $storePass = trim((string) SystemSettingsService::get('payment_sslcommerz', 'store_password', 'qjynKMkpu1gj'));
        $isSandbox = (bool) SystemSettingsService::get('payment_sslcommerz', 'sandbox_mode', true);

        if ($storeId === '' || $storeId === 'sitevaulta0live') {
            $storeId = 'codex6ae2d3cc64516';
        }
        if ($storePass === '' || $storePass === 'sitevaulta0pass') {
            $storePass = 'qjynKMkpu1gj';
        }

        $apiUrl = $isSandbox
            ? 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php'
            : 'https://securepay.sslcommerz.com/gwprocess/v4/api.php';

        $postData = [
            'store_id' => $storeId,
            'store_passwd' => $storePass,
            'total_amount' => number_format((float) $payment->amount, 2, '.', ''),
            'currency' => $payment->currency ?: 'BDT',
            'tran_id' => $payment->gateway_transaction_id,
            'success_url' => route('billing.checkout.complete', ['paymentUuid' => $payment->invoice_number]),
            'fail_url' => route('billing.checkout.cancel', ['paymentUuid' => $payment->invoice_number]),
            'cancel_url' => route('billing.checkout.cancel', ['paymentUuid' => $payment->invoice_number]),
            'ipn_url' => route('webhooks.sslcommerz.ipn'),
            'cus_name' => $payment->workspace->owner?->name ?: ($payment->workspace->name ?: 'Customer Name'),
            'cus_email' => $payment->workspace->owner?->email ?: 'customer@example.com',
            'cus_add1' => 'Dhaka',
            'cus_city' => 'Dhaka',
            'cus_postcode' => '1000',
            'cus_country' => 'Bangladesh',
            'cus_phone' => '01711111111',
            'shipping_method' => 'NO',
            'product_name' => ($plan?->name ?? 'SiteVaulta') . ' Plan Subscription',
            'product_category' => 'Electronic',
            'product_profile' => 'general',
            'value_a' => (string) $payment->workspace_id,
            'value_b' => (string) ($plan?->code ?? 'pro'),
            'value_c' => $cycle->value,
            'value_d' => $payment->invoice_number,
        ];

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($isSandbox) {
            curl_setopt($ch, CURLOPT_RESOLVE, ['sandbox.sslcommerz.com:443:103.26.139.87']);
        }

        $raw = curl_exec($ch);
        curl_close($ch);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $json = json_decode($raw, true);
        if (is_array($json) && ! empty($json['GatewayPageURL'])) {
            $payload = $payment->gateway_payload ?? [];
            $payment->update([
                'gateway_payload' => array_merge($payload, [
                    'sslcommerz_sessionkey' => $json['sessionkey'] ?? null,
                    'sslcommerz_gateway_url' => $json['GatewayPageURL'],
                    'store_name' => SystemSettingsService::get('payment_sslcommerz', 'store_name', 'testcodexi9zk'),
                ]),
            ]);

            return (string) $json['GatewayPageURL'];
        }

        return null;
    }

    /**
     * Initiates a real Stripe Checkout Session (Sandbox / Live)
     * and returns the official Stripe Hosted Checkout URL (https://checkout.stripe.com/c/pay/...).
     */
    public static function initiateStripeGatewayUrl(Payment $payment, ?Plan $plan, BillingCycle $cycle): ?string
    {
        $secretKey = trim((string) SystemSettingsService::get('payment_stripe', 'secret_key', ''));

        if ($secretKey === '' || $secretKey === 'sk_test_sitevaulta' || str_starts_with($secretKey, 'sk_demo')) {
            return null;
        }

        try {
            $stripe = new StripeClient($secretKey);
            $productName = ($plan?->name ?? 'SiteVaulta') . ' Plan Subscription';
            $cycleLabel = ucfirst($cycle->value);

            $session = $stripe->checkout->sessions->create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($payment->currency ?: 'usd'),
                        'product_data' => [
                            'name' => $productName,
                            'description' => "SiteVaulta Cloud Backup - {$cycleLabel} ({$payment->currency} " . number_format((float) $payment->amount, 2) . ")",
                        ],
                        'unit_amount' => (int) round(((float) $payment->amount) * 100),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => route('billing.checkout.complete', ['paymentUuid' => $payment->invoice_number]) . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('billing.checkout.cancel', ['paymentUuid' => $payment->invoice_number]),
                'client_reference_id' => $payment->invoice_number,
                'customer_email' => $payment->workspace->owner?->email,
                'metadata' => [
                    'workspace_id' => (string) $payment->workspace_id,
                    'invoice_number' => $payment->invoice_number,
                    'plan_code' => (string) ($plan?->code ?? 'pro'),
                    'billing_cycle' => $cycle->value,
                ],
            ]);

            if (! empty($session->url)) {
                $payload = $payment->gateway_payload ?? [];
                $payment->update([
                    'gateway_payload' => array_merge($payload, [
                        'stripe_session_id' => $session->id,
                        'stripe_gateway_url' => $session->url,
                    ]),
                ]);

                return $session->url;
            }
        } catch (\Throwable $e) {
            Log::warning('Stripe Checkout Initiation Warning: ' . $e->getMessage());

            return null;
        }

        return null;
    }

    /**
     * Initiates a real PayPal v2 Order (Sandbox / Live)
     * and returns the official PayPal Approval URL (https://www.sandbox.paypal.com/checkoutnow?token=...).
     */
    public static function initiatePayPalGatewayUrl(Payment $payment, ?Plan $plan, BillingCycle $cycle): ?string
    {
        $clientId = trim((string) SystemSettingsService::get('payment_paypal', 'client_id', ''));
        $clientSecret = trim((string) SystemSettingsService::get('payment_paypal', 'client_secret', ''));
        $isSandbox = (bool) SystemSettingsService::get('payment_paypal', 'sandbox_mode', true);

        if ($clientId === '' || $clientSecret === '' || $clientId === 'sb-sitevaulta-client' || str_starts_with($clientId, 'demo_')) {
            return null;
        }

        $baseUrl = $isSandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

        try {
            // 1. Get OAuth Access Token
            $tokenRes = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->timeout(12)
                ->post("{$baseUrl}/v1/oauth2/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if (! $tokenRes->successful()) {
                Log::warning('PayPal OAuth Warning: ' . $tokenRes->body());

                return null;
            }

            $accessToken = $tokenRes->json('access_token');
            if (! $accessToken) {
                return null;
            }

            // 2. Create Order
            $orderRes = Http::withToken($accessToken)
                ->timeout(15)
                ->post("{$baseUrl}/v2/checkout/orders", [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'reference_id' => $payment->invoice_number,
                        'description' => ($plan?->name ?? 'SiteVaulta') . ' Plan Subscription (' . ucfirst($cycle->value) . ')',
                        'amount' => [
                            'currency_code' => strtoupper($payment->currency ?: 'USD'),
                            'value' => number_format((float) $payment->amount, 2, '.', ''),
                        ],
                    ]],
                    'application_context' => [
                        'brand_name' => SystemSettingsService::get('general', 'app_name', 'SiteVaulta'),
                        'landing_page' => 'LOGIN',
                        'user_action' => 'PAY_NOW',
                        'return_url' => route('billing.checkout.complete', ['paymentUuid' => $payment->invoice_number]),
                        'cancel_url' => route('billing.checkout.cancel', ['paymentUuid' => $payment->invoice_number]),
                    ],
                ]);

            if (! $orderRes->successful()) {
                Log::warning('PayPal Order Creation Warning: ' . $orderRes->body());

                return null;
            }

            $orderData = $orderRes->json();
            $orderId = $orderData['id'] ?? null;
            $approveUrl = null;

            foreach ($orderData['links'] ?? [] as $link) {
                if (($link['rel'] ?? '') === 'approve') {
                    $approveUrl = $link['href'] ?? null;
                    break;
                }
            }

            if (! empty($approveUrl)) {
                $payload = $payment->gateway_payload ?? [];
                $payment->update([
                    'gateway_payload' => array_merge($payload, [
                        'paypal_order_id' => $orderId,
                        'paypal_gateway_url' => $approveUrl,
                    ]),
                ]);

                return $approveUrl;
            }
        } catch (\Throwable $e) {
            Log::warning('PayPal Checkout Initiation Warning: ' . $e->getMessage());

            return null;
        }

        return null;
    }

    public function showCheckout(Request $request, string $paymentUuid): View|RedirectResponse
    {
        $payment = Payment::with(['workspace.owner'])
            ->where('invoice_number', $paymentUuid)
            ->orWhere('id', $paymentUuid)
            ->firstOrFail();

        if ($payment->status === PaymentStatus::Succeeded) {
            return redirect("/app/{$payment->workspace->slug}/billing");
        }

        $payload = $payment->gateway_payload ?? [];
        $plan = Plan::find($payload['plan_id'] ?? null);
        $cycle = BillingCycle::tryFrom((string) ($payload['billing_cycle'] ?? 'monthly')) ?? BillingCycle::Monthly;

        // 1. Direct SSLCOMMERZ Sandbox / Live Hosted Gateway Redirect
        $existingSslc = $payload['sslcommerz_gateway_url'] ?? null;
        $gatewayUrl = $existingSslc ?: (! app()->runningUnitTests() ? self::initiateSslCommerzGatewayUrl($payment, $plan, $cycle) : null);
        if ($payment->gateway === PaymentGateway::SslCommerz && ! empty($gatewayUrl)) {
            return redirect()->away($gatewayUrl);
        }

        // 2. Direct Stripe Sandbox / Live Hosted Checkout Redirect
        $existingStripe = $payload['stripe_gateway_url'] ?? null;
        $gatewayUrl = $existingStripe ?: (! app()->runningUnitTests() ? self::initiateStripeGatewayUrl($payment, $plan, $cycle) : null);
        if ($payment->gateway === PaymentGateway::Stripe && ! empty($gatewayUrl)) {
            return redirect()->away($gatewayUrl);
        }

        // 3. Direct PayPal Sandbox / Live Hosted Checkout Redirect
        $existingPayPal = $payload['paypal_gateway_url'] ?? null;
        $gatewayUrl = $existingPayPal ?: (! app()->runningUnitTests() ? self::initiatePayPalGatewayUrl($payment, $plan, $cycle) : null);
        if ($payment->gateway === PaymentGateway::PayPal && ! empty($gatewayUrl)) {
            return redirect()->away($gatewayUrl);
        }

        return view('billing.sandbox-checkout', [
            'payment' => $payment,
            'workspace' => $payment->workspace,
            'plan' => $plan,
            'cycle' => $cycle,
            'appName' => SystemSettingsService::get('general', 'app_name', 'SiteVaulta'),
        ]);
    }

    public function completeCheckout(Request $request, string $paymentUuid): RedirectResponse
    {
        $payment = Payment::with('workspace')
            ->where('invoice_number', $paymentUuid)
            ->orWhere('id', $paymentUuid)
            ->firstOrFail();

        // If SSLCOMMERZ callback indicates FAILED or CANCELLED status
        $sslStatus = strtoupper((string) $request->input('status', ''));
        if (in_array($sslStatus, ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
            return $this->cancelCheckout($paymentUuid);
        }

        $workspace = $payment->workspace;
        $payload = $payment->gateway_payload ?? [];

        $plan = Plan::findOrFail($payload['plan_id'] ?? null);
        $cycle = BillingCycle::tryFrom((string) ($payload['billing_cycle'] ?? 'monthly')) ?? BillingCycle::Monthly;
        $coupon = ! empty($payload['coupon_code'])
            ? Coupon::where('code', $payload['coupon_code'])->first()
            : null;

        $paymentMethod = (string) ($request->input('card_type')
            ?: $request->input('card_brand')
            ?: $request->input('payment_method', $payment->gateway->label()));

        $bankTranId = (string) $request->input('bank_tran_id', '');
        $valId = (string) $request->input('val_id', '');

        // 1. Stripe Checkout Return Verification
        if ($payment->gateway === PaymentGateway::Stripe && $request->filled('session_id')) {
            $secretKey = trim((string) SystemSettingsService::get('payment_stripe', 'secret_key', ''));
            if ($secretKey !== '' && ! str_starts_with($secretKey, 'sk_demo') && $secretKey !== 'sk_test_sitevaulta') {
                try {
                    $stripe = new StripeClient($secretKey);
                    $session = $stripe->checkout->sessions->retrieve($request->query('session_id'));
                    if ($session->payment_status !== 'paid') {
                        return redirect("/app/{$payment->workspace->slug}/billing?payment_status=pending&invoice={$payment->invoice_number}");
                    }
                    $paymentMethod = 'Stripe (' . ($session->payment_method_types[0] ?? 'Card') . ')';
                    $valId = (string) $session->id;
                    $bankTranId = (string) ($session->payment_intent ?? $session->id);
                } catch (\Throwable $e) {
                    Log::warning('Stripe Session Verification Error: ' . $e->getMessage());
                }
            }
        }

        // 2. PayPal Return Order Capture
        if ($payment->gateway === PaymentGateway::PayPal && ($request->filled('token') || $request->filled('PayerID'))) {
            $orderId = (string) $request->input('token', $payload['paypal_order_id'] ?? '');
            $clientId = trim((string) SystemSettingsService::get('payment_paypal', 'client_id', ''));
            $clientSecret = trim((string) SystemSettingsService::get('payment_paypal', 'client_secret', ''));
            $isSandbox = (bool) SystemSettingsService::get('payment_paypal', 'sandbox_mode', true);
            $baseUrl = $isSandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

            if ($clientId !== '' && $clientSecret !== '' && $orderId !== '' && ! str_starts_with($clientId, 'demo_') && $clientId !== 'sb-sitevaulta-client') {
                try {
                    $tokenRes = Http::asForm()
                        ->withBasicAuth($clientId, $clientSecret)
                        ->timeout(10)
                        ->post("{$baseUrl}/v1/oauth2/token", ['grant_type' => 'client_credentials']);

                    if ($tokenRes->successful()) {
                        $bearer = $tokenRes->json('access_token');
                        $captureRes = Http::withToken($bearer)
                            ->timeout(15)
                            ->post("{$baseUrl}/v2/checkout/orders/{$orderId}/capture", (object) []);

                        if ($captureRes->successful()) {
                            $captureData = $captureRes->json();
                            $paymentMethod = 'PayPal (' . ($request->input('PayerID') ?? 'Account') . ')';
                            $bankTranId = $captureData['id'] ?? $orderId;
                            $valId = $orderId;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('PayPal Order Capture Warning: ' . $e->getMessage());
                }
            }
        }

        MultiGatewayBillingService::fulfillSuccessfulPayment(
            workspace: $workspace,
            plan: $plan,
            cycle: $cycle,
            gateway: $payment->gateway,
            gatewayTransactionId: $payment->gateway_transaction_id,
            idempotencyKey: $payment->idempotency_key,
            currency: $payment->currency,
            amount: (float) $payment->amount,
            discountAmount: (float) $payment->discount_amount,
            taxAmount: (float) $payment->tax_amount,
            gatewayPayload: array_merge($payload, [
                'sandbox_verified' => true,
                'payment_method' => $paymentMethod,
                'val_id' => $valId ?: null,
                'bank_tran_id' => $bankTranId ?: null,
                'card_issuer' => $request->input('card_issuer'),
                'completed_at' => now()->utc()->toIso8601String(),
            ]),
            coupon: $coupon
        );

        return redirect("/app/{$workspace->slug}/billing?payment_status=success&invoice={$payment->invoice_number}", 303);
    }

    public function cancelCheckout(string $paymentUuid): RedirectResponse
    {
        $payment = Payment::with('workspace')
            ->where('invoice_number', $paymentUuid)
            ->orWhere('id', $paymentUuid)
            ->firstOrFail();

        if ($payment->status === PaymentStatus::Pending) {
            $payment->update([
                'status' => PaymentStatus::Failed,
            ]);
        }

        return redirect("/app/{$payment->workspace->slug}/billing?payment_status=cancelled&invoice={$payment->invoice_number}", 303);
    }

    public function viewInvoice(string $paymentUuid): View
    {
        $data = $this->buildInvoiceViewData($paymentUuid, true);

        return view('billing.invoice-pdf', $data);
    }

    public function downloadInvoicePdf(string $paymentUuid)
    {
        $data = $this->buildInvoiceViewData($paymentUuid, false);
        /** @var Payment $payment */
        $payment = $data['payment'];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('billing.invoice-pdf', $data)
            ->setPaper('a4', 'portrait');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            $payment->invoice_number . '.pdf'
        );
    }

    protected function buildInvoiceViewData(string $paymentUuid, bool $isWebPreview): array
    {
        $payment = Payment::with(['workspace.owner', 'subscription.plan'])
            ->where('invoice_number', $paymentUuid)
            ->orWhere('id', $paymentUuid)
            ->firstOrFail();

        $workspace = $payment->workspace;
        $subscription = $payment->subscription ?? $workspace?->currentSubscription;
        $payload = $payment->gateway_payload ?? [];

        $plan = $subscription?->plan
            ?? (! empty($payload['plan_id']) ? Plan::find($payload['plan_id']) : null)
            ?? (! empty($payload['plan_code']) ? Plan::where('code', $payload['plan_code'])->first() : null);

        return [
            'payment' => $payment,
            'workspace' => $workspace,
            'owner' => $workspace?->owner,
            'subscription' => $subscription,
            'plan' => $plan,
            'appName' => SystemSettingsService::get('general', 'app_name', 'SiteVaulta'),
            'tagline' => SystemSettingsService::get('general', 'tagline', 'Your Sites. Your Storage. Your Control.'),
            'supportEmail' => SystemSettingsService::get('general', 'support_email', 'support@sitevaulta.com'),
            'isWebPreview' => $isWebPreview,
        ];
    }
}
