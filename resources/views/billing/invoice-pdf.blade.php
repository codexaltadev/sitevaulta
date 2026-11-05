<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $payment->invoice_number }} — {{ $appName }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 18px 26px;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: "DejaVu Sans", "Helvetica Neue", Helvetica, Arial, sans-serif;
            font-size: 11px;
            line-height: 1.42;
            color: #1e293b;
            background: {{ !empty($isWebPreview) ? '#0f172a' : '#ffffff' }};
            margin: 0;
            padding: {{ !empty($isWebPreview) ? '24px 16px' : '0' }};
        }
        .page-sheet {
            max-width: 790px;
            margin: 0 auto;
            background: #ffffff;
            padding: {{ !empty($isWebPreview) ? '32px 36px' : '0' }};
            border-radius: {{ !empty($isWebPreview) ? '12px' : '0' }};
            box-shadow: {{ !empty($isWebPreview) ? '0 25px 50px -12px rgba(0,0,0,0.5)' : 'none' }};
            page-break-inside: avoid;
            page-break-after: avoid;
        }
        .web-toolbar {
            max-width: 790px;
            margin: 0 auto 14px;
            background: #1e293b;
            border: 1px solid #334155;
            padding: 10px 18px;
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #f8fafc;
        }
        .web-toolbar a, .web-toolbar button {
            display: inline-block;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            border: none;
        }
        .btn-download { background: #10b981; color: #052e16; }
        .btn-print { background: #334155; color: #f8fafc; margin-right: 8px; }
        .btn-back { color: #94a3b8; font-weight: 600; }
        @media print {
            body { background: #ffffff; padding: 0; }
            .page-sheet { box-shadow: none; padding: 0; max-width: 100%; }
            .web-toolbar { display: none !important; }
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .top-accent {
            height: 5px;
            background: #10b981;
            margin-bottom: 14px;
            border-radius: 3px;
        }
        .brand-title {
            font-size: 21px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            margin: 0;
        }
        .brand-dot {
            color: #10b981;
        }
        .brand-sub {
            font-size: 10px;
            color: #64748b;
            margin-top: 2px;
        }
        .invoice-heading {
            font-size: 19px;
            font-weight: 800;
            color: #0f172a;
            text-align: right;
            margin: 0;
            letter-spacing: 0.5px;
        }
        .invoice-number {
            font-size: 12px;
            font-weight: 700;
            color: #059669;
            text-align: right;
            margin-top: 2px;
        }
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 4px;
            font-size: 9.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            margin-top: 5px;
        }
        .status-paid {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        .status-pending {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }
        .status-failed {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .divider {
            border-top: 1px solid #e2e8f0;
            margin: 12px 0;
        }
        .info-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 13px;
            vertical-align: top;
        }
        .section-label {
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            color: #64748b;
            margin-bottom: 4px;
        }
        .info-title {
            font-size: 12.5px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 3px;
        }
        .info-line {
            font-size: 10.5px;
            color: #475569;
            margin-bottom: 1.5px;
        }
        .items-table {
            margin-top: 14px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            overflow: hidden;
        }
        .items-table th {
            background: #0f172a;
            color: #f8fafc;
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 8px 12px;
            text-align: left;
        }
        .items-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }
        .item-name {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 3px;
        }
        .item-desc {
            font-size: 10px;
            color: #64748b;
            line-height: 1.4;
        }
        .bottom-row {
            margin-top: 14px;
        }
        .totals-table {
            width: 100%;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
        .totals-table td {
            padding: 5px 10px;
            font-size: 11px;
        }
        .totals-table .label {
            color: #475569;
            text-align: left;
        }
        .totals-table .val {
            color: #0f172a;
            font-weight: 700;
            text-align: right;
        }
        .totals-table .grand-row td {
            border-top: 1.5px solid #0f172a;
            border-bottom: 1.5px solid #0f172a;
            padding: 7px 10px;
            font-size: 12.5px;
            font-weight: 800;
            color: #059669;
            background: #f0fdf4;
        }
        .verification-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-left: 3.5px solid #10b981;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 9.8px;
            color: #475569;
            line-height: 1.45;
        }
        .footer {
            margin-top: 14px;
            padding-top: 8px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            font-size: 9.5px;
            color: #64748b;
        }
    </style>
</head>
<body>
    @if(!empty($isWebPreview))
        <div class="web-toolbar">
            <a href="{{ url('/app/' . ($workspace?->slug ?? '') . '/billing') }}" class="btn-back">&larr; Back to Billing Portal</a>
            <div>
                <button onclick="window.print()" class="btn-print">🖨️ Print Invoice</button>
                <a href="{{ route('billing.invoice.pdf', ['paymentUuid' => $payment->invoice_number]) }}" class="btn-download">📥 Download Official PDF</a>
            </div>
        </div>
    @endif

    <div class="page-sheet">
        <div class="top-accent"></div>

        <!-- Header: Brand & Invoice Identity -->
        <table>
            <tr>
                <td style="width: 55%; vertical-align: top;">
                    <h1 class="brand-title">{{ $appName }}<span class="brand-dot">.</span></h1>
                    <div class="brand-sub">{{ $tagline }}</div>
                    <div style="margin-top: 6px; font-size: 10px; color: #475569;">
                        <strong>Billing &amp; Operations Division</strong> &bull; {{ $supportEmail }}<br>
                        Portal: {{ url('/') }}
                    </div>
                </td>
                <td style="width: 45%; vertical-align: top; text-align: right;">
                    <h2 class="invoice-heading">TAX INVOICE</h2>
                    <div class="invoice-number">#{{ $payment->invoice_number }}</div>
                    @php
                        $statusVal = $payment->status->value;
                        $badgeClass = match ($statusVal) {
                            'succeeded' => 'status-paid',
                            'pending' => 'status-pending',
                            default => 'status-failed',
                        };
                        $badgeText = match ($statusVal) {
                            'succeeded' => 'PAID IN FULL',
                            'pending' => 'PAYMENT PENDING',
                            default => strtoupper($statusVal),
                        };
                    @endphp
                    <div>
                        <span class="status-badge {{ $badgeClass }}">{{ $badgeText }}</span>
                    </div>
                    <div style="margin-top: 6px; font-size: 10px; color: #475569;">
                        <strong>Issue Date:</strong> {{ ($payment->paid_at ?? $payment->created_at)?->format('d M Y, H:i') }} UTC
                        @if($subscription && $subscription->current_period_ends_at)
                            <br><strong>Valid Until:</strong> {{ $subscription->current_period_ends_at->format('d M Y') }} UTC
                        @endif
                    </div>
                </td>
            </tr>
        </table>

        <div class="divider"></div>

        <!-- Billed To & Payment Gateway Metadata -->
        <table>
            <tr>
                <td style="width: 49%; padding-right: 8px; vertical-align: top;">
                    <div class="info-box">
                        <div class="section-label">Billed To (Customer Workspace)</div>
                        <div class="info-title">{{ $workspace?->name ?? 'Customer Workspace' }}</div>
                        <div class="info-line"><strong>Workspace ID:</strong> {{ $workspace?->slug ?? 'N/A' }}</div>
                        <div class="info-line"><strong>Account Owner:</strong> {{ $owner?->name ?? 'Workspace Owner' }} ({{ $owner?->email ?? 'N/A' }})</div>
                        <div class="info-line"><strong>Timezone / Currency:</strong> {{ $workspace?->timezone ?? 'Asia/Dhaka' }} ({{ $payment->currency }})</div>
                    </div>
                </td>
                <td style="width: 2%;"></td>
                <td style="width: 49%; padding-left: 8px; vertical-align: top;">
                    <div class="info-box">
                        <div class="section-label">Payment &amp; Gateway Settlement</div>
                        <div class="info-title">{{ $payment->gateway->label() }}</div>
                        <div class="info-line"><strong>Gateway Tx ID:</strong> {{ $payment->gateway_transaction_id }}</div>
                        <div class="info-line"><strong>Payment Method:</strong> {{ strtoupper(str_replace('_', ' ', (string) ($payment->gateway_payload['payment_method'] ?? $payment->gateway->value))) }}</div>
                        <div class="info-line"><strong>Paid Timestamp:</strong> {{ $payment->paid_at ? $payment->paid_at->format('d M Y, H:i:s') . ' UTC' : 'Awaiting Settlement' }}</div>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Line Items Table -->
        @php
            $currencyCode = $payment->currency === 'BDT' ? 'BDT ' : 'USD $';
            $baseAmount = round((float) $payment->amount + (float) $payment->discount_amount - (float) $payment->tax_amount, 2);
        @endphp
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 54%;">Subscription Plan &amp; Included Entitlements</th>
                    <th style="width: 16%;">Billing Cycle</th>
                    <th style="width: 8%; text-align: center;">Qty</th>
                    <th style="width: 22%; text-align: right;">Amount ({{ $payment->currency }})</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div class="item-name">{{ $plan?->name ?? 'SiteVaulta Cloud Backup Subscription' }} Plan</div>
                        <div class="item-desc">
                            <strong>Included Quotas:</strong>
                            {{ $plan?->max_websites ?? 10 }} Websites &bull;
                            {{ $plan?->max_policies ?? 20 }} Policies &bull;
                            {{ $plan?->max_storage_connections ?? 5 }} BYOS Storage Vaults &bull;
                            {{ $plan?->max_team_members ?? 3 }} Team Seats &bull;
                            {{ $plan?->max_retention_days ?? 90 }}-Day Retention &bull;
                            AES-256-GCM Encryption &amp; SHA-256 Verification
                        </div>
                    </td>
                    <td style="font-weight: 700; color: #334155;">
                        {{ ucfirst((string) ($payment->gateway_payload['billing_cycle'] ?? $subscription?->billing_cycle?->value ?? 'Monthly')) }}
                    </td>
                    <td style="text-align: center; font-weight: 700;">1</td>
                    <td style="text-align: right; font-weight: 700; font-size: 12px;">
                        {{ $currencyCode }}{{ number_format($baseAmount, 2) }}
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Side-by-Side Verification Box & Financial Totals Summary (Guaranteed Single-Page Fit) -->
        <table class="bottom-row">
            <tr>
                <td style="width: 54%; padding-right: 14px; vertical-align: top;">
                    <div class="verification-box">
                        <strong style="color: #0f172a;">Cryptographic Receipt Verification &amp; Audit Reference</strong><br>
                        <strong>Idempotency Ref:</strong> <code>{{ $payment->idempotency_key }}</code><br>
                        @if(!empty($payment->gateway_payload['bank_tran_id']))
                            <strong>Bank / Gateway Ref:</strong> <code>{{ $payment->gateway_payload['bank_tran_id'] }}</code><br>
                        @endif
                        <strong>Digital Receipt SHA-256:</strong> <code>{{ substr(hash('sha256', $payment->invoice_number . '|' . $payment->gateway_transaction_id . '|' . $payment->amount), 0, 32) }}</code><br>
                        <span style="color: #64748b;">Computer-generated official tax invoice issued by {{ $appName }}. No physical signature required.</span>
                    </div>
                </td>
                <td style="width: 46%; vertical-align: top;">
                    <table class="totals-table">
                        <tr>
                            <td class="label">Subtotal:</td>
                            <td class="val">{{ $currencyCode }}{{ number_format($baseAmount, 2) }}</td>
                        </tr>
                        @if((float) $payment->discount_amount > 0)
                            <tr>
                                <td class="label" style="color: #059669;">
                                    Discount
                                    @if(!empty($payment->gateway_payload['coupon_code']))
                                        ({{ $payment->gateway_payload['coupon_code'] }})
                                    @endif:
                                </td>
                                <td class="val" style="color: #059669;">- {{ $currencyCode }}{{ number_format((float) $payment->discount_amount, 2) }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td class="label">Tax / VAT:</td>
                            <td class="val">+ {{ $currencyCode }}{{ number_format((float) $payment->tax_amount, 2) }}</td>
                        </tr>
                        <tr class="grand-row">
                            <td>Total Payable:</td>
                            <td style="text-align: right;">{{ $currencyCode }}{{ number_format((float) $payment->amount, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="label">Amount Paid:</td>
                            <td class="val">
                                {{ $payment->status->value === 'succeeded' ? $currencyCode . number_format((float) $payment->amount, 2) : $currencyCode . '0.00' }}
                            </td>
                        </tr>
                        <tr>
                            <td class="label" style="font-weight: 700;">Balance Due:</td>
                            <td class="val">
                                {{ $payment->status->value === 'succeeded' ? $currencyCode . '0.00' : $currencyCode . number_format((float) $payment->amount, 2) }}
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <div class="footer">
            Thank you for trusting <strong>{{ $appName }}</strong> ({{ $tagline }}) &bull; Support: {{ $supportEmail }}
        </div>
    </div>
</body>
</html>
