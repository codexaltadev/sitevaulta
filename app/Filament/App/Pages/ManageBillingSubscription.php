<?php

namespace App\Filament\App\Pages;

use App\Enums\BillingCycle;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Billing\MultiGatewayBillingService;
use App\Services\Settings\SystemSettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

class ManageBillingSubscription extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string | UnitEnum | null $navigationGroup = 'Workspace & Billing';

    protected static ?string $navigationLabel = 'Billing & Subscription';

    protected static ?string $title = 'Subscription, Quotas & Multi-Gateway Billing';

    protected static ?string $slug = 'billing';

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public function mount(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();
        $defaultPlan = Plan::where('code', 'pro')->first() ?? Plan::where('is_active', true)->first();

        $this->form->fill([
            'plan_id' => $workspace->currentSubscription?->plan_id ?? $defaultPlan?->id,
            'billing_cycle' => BillingCycle::Monthly->value,
            'currency' => $workspace->billing_currency ?: 'BDT',
            'gateway' => ($workspace->billing_currency === 'USD') ? PaymentGateway::Stripe->value : PaymentGateway::SslCommerz->value,
            'coupon_code' => '',
        ]);

        $paymentStatus = request()->query('payment_status');
        $invoiceNum = request()->query('invoice');
        if ($paymentStatus === 'success') {
            Notification::make()
                ->title('Payment Authorized & Subscription Activated!')
                ->body("Invoice {$invoiceNum} has been verified and your workspace plan quotas are now active.")
                ->success()
                ->send();
        } elseif ($paymentStatus === 'cancelled') {
            Notification::make()
                ->title('Payment Cancelled or Failed')
                ->body("Checkout for invoice {$invoiceNum} was cancelled. You can retry anytime.")
                ->warning()
                ->send();
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Current Subscription & Entitlement Usage')
                    ->schema([
                        Placeholder::make('entitlement_overview')
                            ->label('Workspace Plan & Quota Summary')
                            ->content(function (): HtmlString {
                                /** @var Workspace $workspace */
                                $workspace = Filament::getTenant();
                                $sub = $workspace->currentSubscription()->with('plan')->first();
                                $plan = $sub?->plan;

                                $statusBadge = $sub?->status?->label() ?? 'No Active Subscription';
                                $expiryDate = $sub?->getExpiryDate();
                                $remainingDays = $sub ? $sub->getRemainingDays() : 0;
                                $isExpiringSoon = $sub ? $sub->isExpiringSoon(7) : false;
                                $validityDesc = $sub ? $sub->getValidityDescription() : 'No subscription active';

                                $trialNotice = '';
                                if ($sub && $sub->status === SubscriptionStatus::Trialing) {
                                    $trialNotice = "<div style='margin-top:10px;padding:10px 14px;background:rgba(234,179,8,0.12);border:1px solid rgba(234,179,8,0.3);border-radius:8px;color:var(--sv-warning);font-weight:700;'>⏳ Free Trial Active — {$remainingDays} day(s) remaining (Ends {$expiryDate?->toDateTimeString()} UTC)</div>";
                                } elseif ($sub && $isExpiringSoon) {
                                    $alertColor = ($sub->status === SubscriptionStatus::PastDue || $sub->status === SubscriptionStatus::Expired) ? 'var(--sv-danger)' : 'var(--sv-warning)';
                                    $trialNotice = "<div style='margin-top:10px;padding:10px 14px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:8px;color:{$alertColor};font-weight:700;'>⚠️ Attention: {$validityDesc} — Please renew your subscription to avoid service interruption.</div>";
                                }

                                $expiryBadge = '';
                                if ($sub && $expiryDate) {
                                    $expiryBadge = "<div style='margin-top:8px;font-size:13px;color:var(--sv-text-dim);'><strong>Subscription Validity:</strong> <span style='font-weight:700;color:var(--sv-primary);'>{$expiryDate->format('M d, Y')}</span> ({$remainingDays} day" . ($remainingDays === 1 ? '' : 's') . " remaining &bull; {$expiryDate->format('H:i:s')} UTC)</div>";
                                }

                                $websitesUsed = $workspace->websites()->count();
                                $websitesMax = $plan?->max_websites ?? 1;
                                $destinationsUsed = $workspace->storageConnections()->count();
                                $destinationsMax = $plan?->max_storage_connections ?? 1;
                                $membersUsed = $workspace->members()->count();
                                $membersMax = $plan?->max_team_members ?? 1;

                                $activeGateways = implode(' • ', array_map(fn ($g) => $g->label(), PaymentGateway::enabledGateways()));

                                return new HtmlString(
                                    "<div style='padding:18px;border-radius:10px;border:1px solid var(--sv-border);background:var(--sv-bg);color:var(--sv-text);'>" .
                                    "<div style='display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;'>" .
                                    "<div style='font-size:17px;font-weight:800;color:var(--sv-primary);'>Current Plan: " . e($plan?->name ?? 'None') . " <span style='color:var(--sv-text);font-weight:600;'>({$statusBadge})</span></div>" .
                                    ($sub ? "<div style='font-size:12px;padding:4px 10px;border-radius:6px;background:var(--sv-surface);border:1px solid var(--sv-border);color:var(--sv-text-dim);'>Cycle: <strong>" . e($sub->billing_cycle?->label() ?? 'Monthly') . "</strong></div>" : "") .
                                    "</div>" .
                                    $expiryBadge .
                                    $trialNotice .
                                    "<div style='margin-top:14px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;'>" .
                                    "<div style='padding:10px;background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:8px;'><div style='font-size:12px;color:var(--sv-muted);'>Verified Websites</div><div style='font-size:16px;font-weight:700;color:var(--sv-text);'>{$websitesUsed} / {$websitesMax}</div></div>" .
                                    "<div style='padding:10px;background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:8px;'><div style='font-size:12px;color:var(--sv-muted);'>Storage Destinations</div><div style='font-size:16px;font-weight:700;color:var(--sv-text);'>{$destinationsUsed} / {$destinationsMax}</div></div>" .
                                    "<div style='padding:10px;background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:8px;'><div style='font-size:12px;color:var(--sv-muted);'>Team Members</div><div style='font-size:16px;font-weight:700;color:var(--sv-text);'>{$membersUsed} / {$membersMax}</div></div>" .
                                    "</div>" .
                                    "<div style='margin-top:12px;font-size:12px;color:var(--sv-text-dim);'><strong>Active Payment Gateways:</strong> {$activeGateways}</div>" .
                                    "</div>"
                                );
                            }),
                    ]),

                Section::make('Upgrade or Renew Subscription (Redirects to Gateway Sandbox / Live Checkout)')
                    ->columns(2)
                    ->schema([
                        Select::make('plan_id')
                            ->label('Select Subscription Plan')
                            ->options(fn () => Plan::where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))
                            ->live()
                            ->required(),

                        Select::make('billing_cycle')
                            ->label('Billing Cycle')
                            ->options(function (Get $get): array {
                                $plan = Plan::find($get('plan_id'));
                                $currency = (string) ($get('currency') ?: 'BDT');
                                $symbol = $currency === 'BDT' ? '৳' : '$';
                                $discountPct = $plan ? $plan->getYearlyDiscountPercent() : 0.0;
                                $savingsAmt = $plan ? $plan->getYearlySavingsAmount($currency) : 0.0;

                                $yearlyLabel = $discountPct > 0
                                    ? 'Yearly Billing (Save ' . rtrim(rtrim(number_format($discountPct, 2), '0'), '.') . '% — ' . $symbol . number_format($savingsAmt, 2) . ' OFF!)'
                                    : 'Yearly Billing';

                                return [
                                    BillingCycle::Monthly->value => 'Monthly Billing',
                                    BillingCycle::Yearly->value => $yearlyLabel,
                                ];
                            })
                            ->default(BillingCycle::Monthly->value)
                            ->live()
                            ->required(),

                        Select::make('currency')
                            ->label('Billing Currency')
                            ->options([
                                'BDT' => 'BDT (৳ — Bangladeshi Taka)',
                                'USD' => 'USD ($ — US Dollar)',
                            ])
                            ->live()
                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                if ($state === 'BDT') {
                                    $set('gateway', PaymentGateway::SslCommerz->value);
                                } else {
                                    $set('gateway', PaymentGateway::Stripe->value);
                                }
                            })
                            ->required(),

                        Select::make('gateway')
                            ->label('Payment Gateway')
                            ->options(fn (): array => PaymentGateway::enabledGatewayOptions())
                            ->live()
                            ->required(),

                        TextInput::make('coupon_code')
                            ->label('Promo / Coupon Code (Optional)')
                            ->placeholder('e.g., LAUNCH20')
                            ->live(onBlur: true),

                        Placeholder::make('live_checkout_summary')
                            ->label('Live Checkout Breakdown & Plan Action')
                            ->content(function (Get $get): HtmlString {
                                $plan = Plan::find($get('plan_id'));
                                if (! $plan) {
                                    return new HtmlString('Select a plan to view pricing.');
                                }

                                /** @var Workspace $workspace */
                                $workspace = Filament::getTenant();
                                $sub = $workspace->currentSubscription()->with('plan')->first();

                                $cycle = BillingCycle::tryFrom((string) $get('billing_cycle')) ?? BillingCycle::Monthly;
                                $currency = (string) ($get('currency') ?: 'BDT');
                                $couponCode = (string) ($get('coupon_code') ?: '');

                                $pricing = MultiGatewayBillingService::calculateCheckoutPricing($plan, $cycle, $currency, $couponCode);
                                $symbol = $pricing['currency'] === 'BDT' ? '৳' : '$';
                                $yearlyLine = '';

                                if ($cycle === BillingCycle::Yearly && $pricing['yearly_discount_percent'] > 0) {
                                    $pct = rtrim(rtrim(number_format((float) $pricing['yearly_discount_percent'], 2), '0'), '.');
                                    $yearlyLine =
                                        "<div style='color:var(--sv-muted);'><strong>12-Month Regular Price:</strong> <span style='text-decoration:line-through;'>{$symbol}" . number_format((float) $pricing['regular_amount'], 2) . " {$pricing['currency']}</span></div>" .
                                        "<div style='color:var(--sv-success);'><strong>Yearly Package Discount ({$pct}% OFF):</strong> -{$symbol}" . number_format((float) $pricing['yearly_discount_amount'], 2) . " {$pricing['currency']}</div>";
                                }

                                // SaaS Logic Explanation Banner:
                                $actionBanner = '';
                                if ($sub && $sub->status === SubscriptionStatus::Active) {
                                    $isSamePlan = $sub->plan_id === $plan->id;
                                    $isSameCycle = $sub->billing_cycle === $cycle;

                                    if ($isSamePlan && $isSameCycle) {
                                        $windowDays = (int) SystemSettingsService::get('trial_billing', 'renewal_window_days', 7);
                                        $canRenew = $sub->canRenewSamePlan($windowDays);
                                        $currentEnd = $sub->getExpiryDate();
                                        $remainingDays = $sub->getRemainingDays();

                                        if (! $canRenew) {
                                            $actionBanner = "<div style='margin-bottom:12px;padding:12px 14px;border-radius:8px;background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.3);color:var(--sv-primary);font-size:13px;line-height:1.6;'>" .
                                                "<strong>🛡️ Active Subscription in Good Standing:</strong> Your <strong>" . e($plan->name) . " ({$cycle->label()})</strong> is active with <strong>{$remainingDays} days remaining</strong> (valid until " . ($currentEnd ? $currentEnd->format('M d, Y') : 'N/A') . ").<br>" .
                                                "<span style='color:var(--sv-muted);'>To protect your account from accidental double charges, same-plan renewal unlocks <strong>{$windowDays} days prior to expiration</strong>. If you need higher websites or storage quotas right now, please select an <strong>Upgrade Plan</strong> from the dropdown above.</span>" .
                                                "</div>";
                                        } else {
                                            // Subscription Renewal / Period Extension
                                            $baseDate = ($currentEnd && $currentEnd->isFuture()) ? $currentEnd->copy() : now()->utc();
                                            $projectedEnd = match ($cycle) {
                                                BillingCycle::Yearly => $baseDate->addYear(),
                                                BillingCycle::Lifetime => $baseDate->addYears(50),
                                                default => $baseDate->addMonth(),
                                            };
                                            $actionBanner = "<div style='margin-bottom:12px;padding:12px 14px;border-radius:8px;background:rgba(14,165,233,0.12);border:1px solid rgba(14,165,233,0.35);color:var(--sv-info);font-size:13px;'>" .
                                                "<strong>🔄 Renewal Window Open:</strong> You are renewing your active <strong>" . e($plan->name) . "</strong> subscription. " .
                                                "Your remaining <strong>{$remainingDays} days will be preserved</strong>! Expiration will be safely extended from <strong>" . ($currentEnd ? $currentEnd->format('M d, Y') : 'now') . "</strong> to <strong>" . $projectedEnd->format('M d, Y') . "</strong>." .
                                                "</div>";
                                        }
                                    } elseif ($isSamePlan && ! $isSameCycle) {
                                        // Cycle switch
                                        $actionBanner = "<div style='margin-bottom:12px;padding:10px 14px;border-radius:8px;background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);color:var(--sv-success);font-size:13px;'>" .
                                            "<strong>⚡ Billing Cycle Switch:</strong> Switching to <strong>" . e($cycle->label()) . "</strong>. Takes effect immediately for a full 1-" . ($cycle === BillingCycle::Yearly ? 'year' : 'month') . " period with package discount applied." .
                                            "</div>";
                                    } else {
                                        // Upgrade or Switch
                                        $actionBanner = "<div style='margin-bottom:12px;padding:10px 14px;border-radius:8px;background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.3);color:var(--sv-primary);font-size:13px;'>" .
                                            "<strong>🚀 Plan Upgrade / Switch:</strong> Upgrading from <strong>" . e($sub->plan?->name ?? 'Current') . "</strong> to <strong>" . e($plan->name) . "</strong>. Your higher quota limits will activate immediately upon payment confirmation." .
                                            "</div>";
                                    }
                                }

                                return new HtmlString(
                                    "<div style='padding:14px;border-radius:10px;background:var(--sv-bg);border:1px solid var(--sv-primary);color:var(--sv-text);line-height:1.7;'>" .
                                    $actionBanner .
                                    $yearlyLine .
                                    "<div style='color:var(--sv-text-dim);'><strong>Package Price:</strong> {$symbol}" . number_format((float) $pricing['base_amount'], 2) . " {$pricing['currency']}</div>" .
                                    "<div style='color:var(--sv-success);'><strong>Coupon Discount:</strong> -{$symbol}" . number_format((float) $pricing['discount_amount'], 2) . " {$pricing['currency']}</div>" .
                                    "<div style='color:var(--sv-text-dim);'><strong>Tax / VAT:</strong> +{$symbol}" . number_format((float) $pricing['tax_amount'], 2) . " {$pricing['currency']}</div>" .
                                    "<div style='font-size:17px;font-weight:800;margin-top:6px;color:var(--sv-primary);'>Total Payable: {$symbol}" . number_format((float) $pricing['total_amount'], 2) . " {$pricing['currency']}</div>" .
                                    "</div>"
                                );
                            }),
                    ]),

                Section::make('Recent Invoices & Payment History')
                    ->schema([
                        Placeholder::make('invoices_list')
                            ->label('Workspace Invoices')
                            ->content(function (): HtmlString {
                                /** @var Workspace $workspace */
                                $workspace = Filament::getTenant();
                                $payments = $workspace->payments()->latest('id')->limit(10)->get();

                                if ($payments->isEmpty()) {
                                    return new HtmlString('<div style="padding:12px;color:var(--sv-muted);">No invoices generated yet.</div>');
                                }

                                $rows = '';
                                foreach ($payments as $p) {
                                    $viewUrl = route('billing.invoice.view', ['paymentUuid' => $p->invoice_number]);
                                    $pdfUrl = route('billing.invoice.pdf', ['paymentUuid' => $p->invoice_number]);
                                    $actionLink = $p->status === PaymentStatus::Pending
                                        ? "<a href='" . e(route('billing.checkout.show', ['paymentUuid' => $p->invoice_number])) . "' style='color:var(--sv-warning);font-weight:700;text-decoration:underline;'>Resume Sandbox Checkout</a>"
                                        : "<a href='" . e($viewUrl) . "' target='_blank' style='color:var(--sv-info);font-weight:700;text-decoration:underline;margin-right:12px;'>👁️ View Invoice</a>" .
                                          "<a href='" . e($pdfUrl) . "' target='_blank' style='color:var(--sv-primary);font-weight:700;text-decoration:underline;'>📥 Download PDF</a>";

                                    $rows .= "<tr style='background:var(--sv-bg);color:var(--sv-text);'>" .
                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);'><code>" . e($p->invoice_number) . "</code></td>" .
                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);'>" . e($p->gateway->label()) . "</td>" .
                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);font-weight:700;color:var(--sv-primary);'>{$p->amount} {$p->currency}</td>" .
                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);'>" . e($p->status->label()) . "</td>" .
                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);'>" . e($p->paid_at?->toDateTimeString() ?? 'Pending') . "</td>" .
                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);'>{$actionLink}</td>" .
                                        "</tr>";
                                }

                                return new HtmlString(
                                    "<div style='border:1px solid var(--sv-border);border-radius:10px;overflow:hidden;'>" .
                                    "<table style='width:100%;text-align:left;border-collapse:collapse;font-size:13px;'>" .
                                    "<thead><tr style='background:var(--sv-surface);color:var(--sv-text-dim);'>" .
                                    "<th style='padding:10px;'>Invoice #</th><th style='padding:10px;'>Gateway</th><th style='padding:10px;'>Amount</th><th style='padding:10px;'>Status</th><th style='padding:10px;'>Paid At</th><th style='padding:10px;'>Action</th>" .
                                    "</tr></thead><tbody>{$rows}</tbody></table></div>"
                                );
                            }),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('checkout')
                    ->footer([
                        Actions::make([
                            Action::make('checkout')
                                ->label('Proceed to Payment (Renew / Extend or Upgrade)')
                                ->icon(Heroicon::OutlinedArrowRightCircle)
                                ->color('success')
                                ->submit('checkout'),
                        ]),
                    ]),
            ]);
    }

    public function checkout(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();
        $state = $this->form->getState();

        $plan = Plan::findOrFail($state['plan_id']);
        $cycle = BillingCycle::tryFrom((string) $state['billing_cycle']) ?? BillingCycle::Monthly;
        $gateway = PaymentGateway::tryFrom((string) $state['gateway']) ?? PaymentGateway::SslCommerz;
        $currency = (string) ($state['currency'] ?: 'BDT');
        $couponCode = strtoupper(trim((string) ($state['coupon_code'] ?? '')));

        // Standard SaaS Guard: Accidental Duplicate Repurchase Prevention (Renewal Window Policy)
        $currentSub = $workspace->currentSubscription;
        $windowDays = (int) SystemSettingsService::get('trial_billing', 'renewal_window_days', 7);

        if ($currentSub && $currentSub->status === SubscriptionStatus::Active
            && $currentSub->plan_id === $plan->id
            && $currentSub->billing_cycle === $cycle) {

            if (! $currentSub->canRenewSamePlan($windowDays)) {
                $daysRemaining = $currentSub->getRemainingDays();
                $expiryFormatted = $currentSub->getExpiryDate()?->format('M d, Y') ?? 'the expiration date';

                Notification::make()
                    ->title('Renewal Window Not Yet Open')
                    ->body("Your {$plan->name} subscription is active with {$daysRemaining} days remaining (valid until {$expiryFormatted}). To protect you from duplicate charges, same-plan renewal unlocks {$windowDays} days prior to expiration. If you need more storage, websites, or team members immediately, please select an Upgrade Plan.")
                    ->warning()
                    ->persistent()
                    ->send();

                return;
            }
        }

        // SaaS Guard: Reuse existing pending checkout session if created within the last 15 minutes to prevent duplicate invoices
        $existingPending = Payment::where('workspace_id', $workspace->id)
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '>=', now()->subMinutes(15))
            ->get()
            ->first(function (Payment $p) use ($plan, $cycle, $currency, $gateway) {
                $payload = $p->gateway_payload ?? [];

                return ($payload['plan_id'] ?? null) == $plan->id
                    && ($payload['billing_cycle'] ?? null) === $cycle->value
                    && $p->currency === $currency
                    && $p->gateway === $gateway;
            });

        if ($existingPending) {
            if (! app()->runningUnitTests()) {
                $gatewayUrl = match ($gateway) {
                    PaymentGateway::SslCommerz => \App\Http\Controllers\BillingCheckoutController::initiateSslCommerzGatewayUrl($existingPending, $plan, $cycle),
                    PaymentGateway::Stripe => \App\Http\Controllers\BillingCheckoutController::initiateStripeGatewayUrl($existingPending, $plan, $cycle),
                    PaymentGateway::PayPal => \App\Http\Controllers\BillingCheckoutController::initiatePayPalGatewayUrl($existingPending, $plan, $cycle),
                    default => null,
                };

                if (! empty($gatewayUrl)) {
                    $this->redirect($gatewayUrl);

                    return;
                }
            }

            $this->redirect(route('billing.checkout.show', ['paymentUuid' => $existingPending->invoice_number]));

            return;
        }

        $pricing = MultiGatewayBillingService::calculateCheckoutPricing($plan, $cycle, $currency, $couponCode);
        $txId = strtoupper($gateway->value) . '-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));
        $invoiceNumber = 'INV-' . now()->utc()->format('Ymd') . '-' . strtoupper(Str::random(6));

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'subscription_id' => $workspace->currentSubscription?->id,
            'invoice_number' => $invoiceNumber,
            'gateway' => $gateway,
            'gateway_transaction_id' => $txId,
            'idempotency_key' => 'checkout_session_' . $txId,
            'currency' => $pricing['currency'],
            'amount' => $pricing['total_amount'],
            'discount_amount' => $pricing['discount_amount'],
            'tax_amount' => $pricing['tax_amount'],
            'status' => PaymentStatus::Pending,
            'gateway_payload' => [
                'plan_id' => $plan->id,
                'plan_code' => $plan->code,
                'billing_cycle' => $cycle->value,
                'yearly_discount_percent' => $pricing['yearly_discount_percent'],
                'yearly_discount_amount' => $pricing['yearly_discount_amount'],
                'coupon_code' => $couponCode,
                'initiated_by_user_id' => auth()->id(),
            ],
        ]);

        if (! app()->runningUnitTests()) {
            $gatewayUrl = match ($gateway) {
                PaymentGateway::SslCommerz => \App\Http\Controllers\BillingCheckoutController::initiateSslCommerzGatewayUrl($payment, $plan, $cycle),
                PaymentGateway::Stripe => \App\Http\Controllers\BillingCheckoutController::initiateStripeGatewayUrl($payment, $plan, $cycle),
                PaymentGateway::PayPal => \App\Http\Controllers\BillingCheckoutController::initiatePayPalGatewayUrl($payment, $plan, $cycle),
                default => null,
            };

            if (! empty($gatewayUrl)) {
                $this->redirect($gatewayUrl);

                return;
            }
        }

        $this->redirect(route('billing.checkout.show', ['paymentUuid' => $payment->invoice_number]));
    }
}
