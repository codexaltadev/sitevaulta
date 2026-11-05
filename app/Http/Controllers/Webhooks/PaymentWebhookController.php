<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\BillingCycle;
use App\Enums\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Billing\MultiGatewayBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function handleSslCommerzIpn(Request $request): JsonResponse
    {
        $status = strtoupper((string) $request->input('status'));
        $tranId = (string) $request->input('tran_id');
        $workspaceId = (int) $request->input('value_a');
        $planCode = (string) $request->input('value_b', 'starter');
        $cycleVal = (string) $request->input('value_c', 'monthly');
        $amount = (float) $request->input('amount', 0);

        if (! in_array($status, ['VALID', 'VALIDATED'], true) || $tranId === '') {
            return response()->json(['status' => 'ignored', 'reason' => 'Invalid IPN status'], 400);
        }

        $workspace = Workspace::find($workspaceId);
        $plan = Plan::where('code', $planCode)->first();

        if (! $workspace || ! $plan) {
            return response()->json(['status' => 'error', 'reason' => 'Workspace or Plan not found'], 404);
        }

        $cycle = BillingCycle::tryFrom($cycleVal) ?? BillingCycle::Monthly;

        $payment = MultiGatewayBillingService::fulfillSuccessfulPayment(
            workspace: $workspace,
            plan: $plan,
            cycle: $cycle,
            gateway: PaymentGateway::SslCommerz,
            gatewayTransactionId: $tranId,
            idempotencyKey: 'sslc:' . $tranId,
            currency: 'BDT',
            amount: $amount,
            gatewayPayload: $request->only(['tran_id', 'val_id', 'status', 'card_type', 'currency'])
        );

        return response()->json([
            'status' => 'processed',
            'invoice_number' => $payment->invoice_number,
        ]);
    }

    public function handleStripeWebhook(Request $request): JsonResponse
    {
        $eventId = (string) $request->input('id');
        $type = (string) $request->input('type');
        $object = (array) $request->input('data.object', []);

        if ($eventId === '' || ! in_array($type, ['checkout.session.completed', 'invoice.payment_succeeded'], true)) {
            return response()->json(['status' => 'ignored']);
        }

        $metadata = (array) ($object['metadata'] ?? []);
        $workspaceId = (int) ($metadata['workspace_id'] ?? 0);
        $planCode = (string) ($metadata['plan_code'] ?? 'pro');
        $cycleVal = (string) ($metadata['billing_cycle'] ?? 'monthly');
        $amount = ((float) ($object['amount_total'] ?? $object['amount_paid'] ?? 0)) / 100;
        $txnId = (string) ($object['id'] ?? $eventId);

        $workspace = Workspace::find($workspaceId);
        $plan = Plan::where('code', $planCode)->first();

        if (! $workspace || ! $plan) {
            return response()->json(['status' => 'error', 'reason' => 'Invalid metadata'], 404);
        }

        $cycle = BillingCycle::tryFrom($cycleVal) ?? BillingCycle::Monthly;

        $payment = MultiGatewayBillingService::fulfillSuccessfulPayment(
            workspace: $workspace,
            plan: $plan,
            cycle: $cycle,
            gateway: PaymentGateway::Stripe,
            gatewayTransactionId: $txnId,
            idempotencyKey: 'stripe:' . $eventId,
            currency: 'USD',
            amount: $amount,
            gatewayPayload: ['event_id' => $eventId, 'type' => $type]
        );

        return response()->json([
            'status' => 'processed',
            'invoice_number' => $payment->invoice_number,
        ]);
    }

    public function handlePayPalWebhook(Request $request): JsonResponse
    {
        $eventId = (string) $request->input('id');
        $eventType = (string) $request->input('event_type');
        $resource = (array) $request->input('resource', []);

        if ($eventId === '' || ! in_array($eventType, ['PAYMENT.SALE.COMPLETED', 'BILLING.SUBSCRIPTION.ACTIVATED'], true)) {
            return response()->json(['status' => 'ignored']);
        }

        $custom = json_decode((string) ($resource['custom_id'] ?? '{}'), true) ?: [];
        $workspaceId = (int) ($custom['workspace_id'] ?? 0);
        $planCode = (string) ($custom['plan_code'] ?? 'pro');
        $cycleVal = (string) ($custom['billing_cycle'] ?? 'monthly');
        $amount = (float) ($resource['amount']['total'] ?? $resource['billing_info']['last_payment']['amount']['value'] ?? 0);
        $txnId = (string) ($resource['id'] ?? $eventId);

        $workspace = Workspace::find($workspaceId);
        $plan = Plan::where('code', $planCode)->first();

        if (! $workspace || ! $plan) {
            return response()->json(['status' => 'error', 'reason' => 'Workspace or Plan not found'], 404);
        }

        $cycle = BillingCycle::tryFrom($cycleVal) ?? BillingCycle::Monthly;

        $payment = MultiGatewayBillingService::fulfillSuccessfulPayment(
            workspace: $workspace,
            plan: $plan,
            cycle: $cycle,
            gateway: PaymentGateway::PayPal,
            gatewayTransactionId: $txnId,
            idempotencyKey: 'paypal:' . $eventId,
            currency: 'USD',
            amount: $amount,
            gatewayPayload: ['event_id' => $eventId, 'event_type' => $eventType]
        );

        return response()->json([
            'status' => 'processed',
            'invoice_number' => $payment->invoice_number,
        ]);
    }
}

