<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $payment->gateway->label() }} — Sandbox Checkout</title>
    @include('partials.theme-head')
    <style>
        /* Light theme (default) */
        :root {
            --bg: #f1f5f9;
            --surface: #ffffff;
            --surface-alt: #f8fafc;
            --border: #e2e8f0;
            --primary: #059669;
            --primary-dark: #047857;
            --on-primary: #ffffff;
            --text: #0f172a;
            --text-soft: #334155;
            --muted: #64748b;
            --warning-text: #b45309;
            --danger-text: #b91c1c;
            --shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.18);
        }
        /* Dark theme */
        html.dark {
            --bg: #0f172a;
            --surface: #1e293b;
            --surface-alt: #0f172a;
            --border: #334155;
            --primary: #10b981;
            --primary-dark: #059669;
            --on-primary: #052e16;
            --text: #f8fafc;
            --text-soft: #cbd5e1;
            --muted: #94a3b8;
            --warning-text: #fbbf24;
            --danger-text: #fca5a5;
            --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            transition: background-color 0.2s, color 0.2s;
        }
        .checkout-card {
            width: 100%;
            max-width: 520px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        .header {
            padding: 22px 26px;
            background: var(--surface-alt);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-label { font-size: 13px; color: var(--muted); }
        .header-title { font-size: 18px; font-weight: 800; color: var(--text); margin-top: 2px; }
        .badge-sandbox {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning-text);
            border: 1px solid rgba(245, 158, 11, 0.4);
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .body { padding: 26px; }
        .summary-box {
            background: var(--surface-alt);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px;
            margin-bottom: 20px;
        }
        .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--text-soft);
        }
        .row.total {
            border-top: 1px solid var(--border);
            padding-top: 10px;
            margin-top: 10px;
            font-size: 18px;
            font-weight: 800;
            color: var(--primary);
        }
        .btn {
            display: block;
            width: 100%;
            text-align: center;
            text-decoration: none;
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            margin-top: 10px;
        }
        .btn-pay { background: var(--primary); color: var(--on-primary); }
        .btn-pay:hover { background: var(--primary-dark); color: #ffffff; }
        .btn-cancel {
            background: transparent;
            border: 1px solid #ef4444;
            color: var(--danger-text);
            margin-top: 10px;
        }
        .meta-note {
            font-size: 12px;
            color: var(--muted);
            margin-top: 14px;
            line-height: 1.5;
            text-align: center;
        }
    </style>
</head>
<body>
    @include('partials.theme-toggle', ['fixed' => true])
    <div class="checkout-card">
        <div class="header">
            <div>
                <div class="header-label">{{ $appName }} Secure Gateway</div>
                <div class="header-title">
                    {{ $payment->gateway->label() }}
                </div>
            </div>
            <span class="badge-sandbox">Sandbox Mode</span>
        </div>

        <div class="body">
            <div class="summary-box">
                <div class="row">
                    <span>Workspace:</span>
                    <strong>{{ $workspace->name }}</strong>
                </div>
                <div class="row">
                    <span>Selected Plan:</span>
                    <strong>{{ $plan?->name ?? 'Subscription' }} ({{ ucfirst($cycle->value) }})</strong>
                </div>
                <div class="row">
                    <span>Invoice Reference:</span>
                    <code>{{ $payment->invoice_number }}</code>
                </div>
                <div class="row">
                    <span>Gateway Transaction ID:</span>
                    <code>{{ $payment->gateway_transaction_id }}</code>
                </div>
                <div class="row total">
                    <span>Total Payable:</span>
                    <span>{{ $payment->currency === 'BDT' ? '৳' : '$' }}{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</span>
                </div>
            </div>

            @if(!empty($payment->gateway_payload['sslcommerz_gateway_url']))
                <a href="{{ $payment->gateway_payload['sslcommerz_gateway_url'] }}" class="btn btn-pay">
                    🔒 Continue to Official SSLCOMMERZ Sandbox Page &rarr;
                </a>
                <script>
                    window.location.replace(@json($payment->gateway_payload['sslcommerz_gateway_url']));
                </script>
            @else
                <form method="POST" action="{{ route('billing.checkout.complete', ['paymentUuid' => $payment->invoice_number]) }}">
                    @csrf
                    <input type="hidden" name="payment_method" value="{{ $payment->gateway->value }}_sandbox">
                    <button type="submit" class="btn btn-pay">
                        ✅ Complete {{ $payment->gateway->label() }} Sandbox Payment ({{ $payment->currency === 'BDT' ? '৳' : '$' }}{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }})
                    </button>
                </form>
            @endif

            <form method="POST" action="{{ route('billing.checkout.cancel', ['paymentUuid' => $payment->invoice_number]) }}">
                @csrf
                <button type="submit" class="btn btn-cancel">
                    ❌ Cancel Checkout &amp; Return
                </button>
            </form>

            <div class="meta-note">
                Payment instruments (Cards, Mobile Banking, Net Banking) are selected directly inside the official gateway checkout portal.
            </div>
        </div>
    </div>
</body>
</html>
