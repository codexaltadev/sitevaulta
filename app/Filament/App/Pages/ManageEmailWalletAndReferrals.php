<?php

namespace App\Filament\App\Pages;

use App\Enums\BillingCycle;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Http\Controllers\BillingCheckoutController;
use App\Models\EmailPackage;
use App\Models\Payment;
use App\Models\Workspace;
use App\Services\Billing\EmailWalletService;
use App\Services\Security\AuditLogService;
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
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

class ManageEmailWalletAndReferrals extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Workspace & Billing';

    protected static ?string $navigationLabel = 'Email Wallet, SMTP & Referrals';

    protected static ?string $title = 'Digital Email Wallet, Custom SMTP & Referral Rewards';

    protected static ?string $slug = 'email-wallet';

    protected static ?int $navigationSort = 11;

    public ?array $data = [];

    public function mount(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();
        EmailWalletService::ensureReferralCode($workspace);

        if ( EmailPackage::count() === 0 ) {
            EmailWalletService::seedDefaultEmailPackages();
        }

        $defaultPackage = EmailPackage::where('is_active', true)->orderBy('sort_order')->first();
        $smtp = $workspace->custom_smtp_config ?? [];

        $this->form->fill([
            'email_package_id' => $defaultPackage?->id,
            'currency' => $workspace->billing_currency ?: 'BDT',
            'gateway' => ($workspace->billing_currency === 'USD') ? PaymentGateway::Stripe->value : PaymentGateway::SslCommerz->value,
            'email_provider_mode' => $workspace->email_provider_mode ?: 'system_wallet',
            'smtp_host' => $smtp['host'] ?? '',
            'smtp_port' => $smtp['port'] ?? 587,
            'smtp_encryption' => $smtp['encryption'] ?? 'tls',
            'smtp_username' => $smtp['username'] ?? '',
            'smtp_password' => $smtp['password'] ?? '',
            'smtp_from_address' => $smtp['from_address'] ?? (auth()->user()?->email ?? ''),
            'smtp_from_name' => $smtp['from_name'] ?? $workspace->name,
            'test_recipient_email' => auth()->user()?->email ?? '',
            'apply_referral_code' => '',
        ]);

        $paymentStatus = request()->query('payment_status');
        $invoiceNum = request()->query('invoice');
        if ($paymentStatus === 'success') {
            Notification::make()
                ->title('🎉 Email Credits Added to Your Digital Wallet!')
                ->body("Invoice {$invoiceNum} has been verified and your System Email Wallet balance has been topped up.")
                ->success()
                ->send();
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Digital Email Wallet & Delivery Status')
                    ->schema([
                        Placeholder::make('wallet_overview')
                            ->hiddenLabel()
                            ->content(function (): HtmlString {
                                /** @var Workspace $workspace */
                                $workspace = Filament::getTenant();
                                $workspace->refresh();

                                $balance = (int) $workspace->email_credits_balance;
                                $modeLabel = $workspace->email_provider_mode === 'custom_smtp'
                                    ? '🔧 My Custom SMTP (Free & Unlimited)'
                                    : '💳 System Email Wallet (1 Credit / Email)';
                                $hasSmtp = $workspace->hasValidCustomSmtp();
                                $sentCount = $workspace->emailWalletTransactions()->where('type', 'email_sent_deduction')->count();
                                $referralEarned = (int) $workspace->emailWalletTransactions()
                                    ->whereIn('type', ['referral_signup_bonus', 'referral_purchase_reward'])
                                    ->sum('credits_delta');

                                $refCode = EmailWalletService::ensureReferralCode($workspace);
                                $refUrl = url("/r/{$refCode}");

                                $balanceColor = $balance > 20 ? 'var(--sv-success)' : ($balance > 0 ? 'var(--sv-warning)' : 'var(--sv-danger)');

                                return new HtmlString(
                                    "<div style='padding:18px;border-radius:12px;border:1px solid var(--sv-border);background:var(--sv-bg);color:var(--sv-text);'>".
                                    "<div style='display:grid;grid-template-columns:repeat(4,1fr);gap:12px;'>".
                                    "<div style='padding:14px;background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:10px;'>".
                                        "<div style='font-size:12px;color:var(--sv-muted);'>💳 Digital Email Wallet Balance</div>".
                                        "<div style='font-size:22px;font-weight:800;color:{$balanceColor};margin-top:4px;'>".number_format($balance).' Credits</div>'.
                                        "<div style='font-size:11.5px;color:var(--sv-text-dim);margin-top:2px;'>1 Credit = 1 System Email</div>".
                                    '</div>'.
                                    "<div style='padding:14px;background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:10px;'>".
                                        "<div style='font-size:12px;color:var(--sv-muted);'>📡 Default Delivery Mode</div>".
                                        "<div style='font-size:14px;font-weight:700;color:var(--sv-primary);margin-top:6px;'>{$modeLabel}</div>".
                                        "<div style='font-size:11.5px;color:var(--sv-text-dim);margin-top:4px;'>Custom SMTP: ".($hasSmtp ? '✅ Ready' : '⚠️ Not Configured').'</div>'.
                                    '</div>'.
                                    "<div style='padding:14px;background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:10px;'>".
                                        "<div style='font-size:12px;color:var(--sv-muted);'>📨 System Emails Dispatched</div>".
                                        "<div style='font-size:20px;font-weight:800;color:var(--sv-text);margin-top:4px;'>".number_format($sentCount).' Sent</div>'.
                                        "<div style='font-size:11.5px;color:var(--sv-text-dim);margin-top:2px;'>Custom SMTP emails cost 0 credits</div>".
                                    '</div>'.
                                    "<div style='padding:14px;background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:10px;'>".
                                        "<div style='font-size:12px;color:var(--sv-muted);'>🎁 Referral Credits Earned</div>".
                                        "<div style='font-size:20px;font-weight:800;color:var(--sv-success);margin-top:4px;'>+".number_format($referralEarned).' Credits</div>'.
                                        "<div style='font-size:11.5px;color:var(--sv-text-dim);margin-top:2px;'>Code: <code>".e($refCode).'</code></div>'.
                                    '</div>'.
                                    '</div>'.
                                    "<div style='margin-top:12px;padding:10px 14px;background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:8px;font-size:13px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;'>".
                                        "<div><strong>🔗 Your Shareable Referral Link:</strong> <code>".e($refUrl)."</code></div>".
                                        "<span style='color:var(--sv-success);font-weight:700;'>Refer a user who buys any package &rarr; Earn Free Email Credits!</span>".
                                    '</div>'.
                                    '</div>'
                                );
                            }),
                    ]),

                Tabs::make('EmailWalletTabs')
                    ->tabs([
                        Tab::make('💳 1. Buy System Email Packages')
                            ->schema([
                                Section::make('Top-Up Your Digital Email Wallet (For Users Without Custom SMTP)')
                                    ->description('Don\'t have your own SMTP server? Purchase an Email Credit Package below to send backup success & failure alerts using SiteVaulta\'s high-deliverability System Email.')
                                    ->columns(2)
                                    ->schema([
                                        Select::make('email_package_id')
                                            ->label('Select Email Credit Package')
                                            ->options(function (Get $get): array {
                                                $currency = (string) ($get('currency') ?: 'BDT');
                                                $symbol = $currency === 'USD' ? '$' : '৳';

                                                return EmailPackage::where('is_active', true)
                                                    ->orderBy('sort_order')
                                                    ->get()
                                                    ->mapWithKeys(function (EmailPackage $pkg) use ($currency, $symbol) {
                                                        $price = $pkg->getPriceForCurrency($currency);
                                                        $bonusText = $pkg->bonus_credits > 0 ? " (+{$pkg->bonus_credits} Bonus = {$pkg->totalCredits()} Total Credits)" : " ({$pkg->credits_amount} Credits)";

                                                        return [$pkg->id => "{$pkg->name}{$bonusText} — {$symbol}".number_format($price, 2)." {$currency}"];
                                                    })
                                                    ->all();
                                            })
                                            ->live()
                                            ->required()
                                            ->columnSpanFull(),

                                        Select::make('currency')
                                            ->label('Payment Currency')
                                            ->options([
                                                'BDT' => '🇧🇩 BDT (৳) — SSLCOMMERZ (bKash / Nagad / Cards)',
                                                'USD' => '🌐 USD ($) — Stripe / PayPal',
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

                                        Placeholder::make('package_summary')
                                            ->label('Selected Email Package Breakdown')
                                            ->columnSpanFull()
                                            ->content(function (Get $get): HtmlString {
                                                $pkg = EmailPackage::find($get('email_package_id'));
                                                if (! $pkg) {
                                                    return new HtmlString('Select an email credit package above.');
                                                }

                                                $currency = strtoupper((string) ($get('currency') ?: 'BDT')) === 'USD' ? 'USD' : 'BDT';
                                                $symbol = $currency === 'USD' ? '$' : '৳';
                                                $price = $pkg->getPriceForCurrency($currency);
                                                $taxPct = (float) SystemSettingsService::get('trial_billing', 'tax_percentage', 0.0);
                                                $taxAmt = round($price * ($taxPct / 100), 2);
                                                $totalAmt = round($price + $taxAmt, 2);

                                                return new HtmlString(
                                                    "<div style='padding:14px;border-radius:10px;background:var(--sv-bg);border:1px solid var(--sv-primary);color:var(--sv-text);line-height:1.75;'>".
                                                    "<div style='font-size:15px;font-weight:800;color:var(--sv-primary);'>".e($pkg->name).'</div>'.
                                                    "<div style='color:var(--sv-text-dim);font-size:13px;margin-bottom:8px;'>".e($pkg->description).'</div>'.
                                                    "<div><strong>Base Email Credits:</strong> ".number_format($pkg->credits_amount).' Credits</div>'.
                                                    ($pkg->bonus_credits > 0 ? "<div style='color:var(--sv-success);'><strong>Bonus Free Credits:</strong> +".number_format($pkg->bonus_credits).' Credits</div>' : '').
                                                    "<div style='font-weight:700;'><strong>Total Credits Added to Wallet:</strong> ".number_format($pkg->totalCredits()).' Email Credits (Never Expire)</div>'.
                                                    "<div style='font-size:17px;font-weight:800;margin-top:8px;color:var(--sv-primary);'>Total Payable: {$symbol}".number_format($totalAmt, 2)." {$currency}</div>".
                                                    '</div>'
                                                );
                                            }),
                                    ])
                                    ->footerActions([
                                        Action::make('buyEmailPackage')
                                            ->label('Buy Email Package & Top-Up Digital Wallet')
                                            ->icon(Heroicon::OutlinedCreditCard)
                                            ->color('success')
                                            ->action(fn () => $this->buyEmailPackage()),
                                    ]),
                            ]),

                        Tab::make('🔧 2. My Own Custom SMTP (Free)')
                            ->schema([
                                Section::make('Bring Your Own SMTP Server (100% Free & Unlimited Emails)')
                                    ->description('Configure your own SMTP credentials (e.g., Gmail App Password, Amazon SES, Mailgun, Postmark, or cPanel Mail) to send backup run emails without using any Digital Wallet credits.')
                                    ->columns(3)
                                    ->schema([
                                        Select::make('email_provider_mode')
                                            ->label('Default Workspace Email Delivery Mode')
                                            ->options([
                                                'system_wallet' => '💳 Use SiteVaulta System Email (Uses Digital Wallet Credits — 1 Credit / Email)',
                                                'custom_smtp' => '🔧 Use My Own Custom SMTP Server (100% Free & Unlimited)',
                                            ])
                                            ->required()
                                            ->columnSpanFull(),

                                        TextInput::make('smtp_host')
                                            ->label('SMTP Host')
                                            ->placeholder('e.g., smtp.gmail.com or smtp.mailgun.org'),

                                        TextInput::make('smtp_port')
                                            ->label('SMTP Port')
                                            ->numeric()
                                            ->default(587),

                                        Select::make('smtp_encryption')
                                            ->label('Encryption')
                                            ->options([
                                                'tls' => 'TLS (Port 587)',
                                                'ssl' => 'SSL (Port 465)',
                                                '' => 'None (Unencrypted)',
                                            ])
                                            ->default('tls'),

                                        TextInput::make('smtp_username')
                                            ->label('SMTP Username')
                                            ->placeholder('your-smtp-user@yourdomain.com'),

                                        TextInput::make('smtp_password')
                                            ->label('SMTP Password / App Password')
                                            ->password()
                                            ->revealable(),

                                        TextInput::make('smtp_from_address')
                                            ->label('From Email Address')
                                            ->email()
                                            ->placeholder('backups@yourdomain.com'),

                                        TextInput::make('smtp_from_name')
                                            ->label('From Sender Name')
                                            ->placeholder('My Agency Backup Alerts')
                                            ->columnSpan(2),

                                        TextInput::make('test_recipient_email')
                                            ->label('Test Recipient Email (For Testing Your SMTP)')
                                            ->email()
                                            ->placeholder('you@example.com'),
                                    ])
                                    ->footerActions([
                                        Action::make('saveSmtpSettings')
                                            ->label('Save Delivery Mode & Custom SMTP')
                                            ->icon(Heroicon::OutlinedCheckCircle)
                                            ->color('primary')
                                            ->action(fn () => $this->saveSmtpSettings()),

                                        Action::make('testCustomSmtp')
                                            ->label('Send Test Email via My Custom SMTP')
                                            ->icon(Heroicon::OutlinedPaperAirplane)
                                            ->color('info')
                                            ->action(fn () => $this->sendCustomSmtpTestEmail()),
                                    ]),
                            ]),

                        Tab::make('🎁 3. Referral Program (Earn Free Credits)')
                            ->schema([
                                Section::make('Refer Friends & Earn Free Digital Email Wallet Credits')
                                    ->description('When someone creates a workspace using your referral link or code and purchases any Subscription Plan or Email Package, your Digital Email Wallet is automatically credited!')
                                    ->columns(2)
                                    ->schema([
                                        Placeholder::make('referral_program_details')
                                            ->hiddenLabel()
                                            ->columnSpanFull()
                                            ->content(function (): HtmlString {
                                                /** @var Workspace $workspace */
                                                $workspace = Filament::getTenant();
                                                $refCode = EmailWalletService::ensureReferralCode($workspace);
                                                $refUrl = url("/r/{$refCode}");

                                                $signupBonus = (int) SystemSettingsService::get('email_wallet_referral', 'referral_signup_bonus_credits', 25);
                                                $referrerReward = (int) SystemSettingsService::get('email_wallet_referral', 'referral_purchase_referrer_credits', 500);
                                                $refereeBonus = (int) SystemSettingsService::get('email_wallet_referral', 'referral_purchase_referee_credits', 200);

                                                $referredBy = $workspace->referredByWorkspace;
                                                $referredByBanner = $referredBy
                                                    ? "<div style='margin-top:10px;padding:10px 14px;border-radius:8px;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);color:var(--sv-success);font-weight:600;'>✅ Your workspace was referred by <strong>".e($referredBy->name)."</strong> (<code>".e($referredBy->referral_code).'</code>).</div>'
                                                    : '';

                                                return new HtmlString(
                                                    "<div style='padding:16px;border-radius:10px;background:var(--sv-bg);border:1px solid var(--sv-border);line-height:1.75;'>".
                                                    "<div style='font-size:16px;font-weight:800;color:var(--sv-primary);margin-bottom:8px;'>🚀 How the Referral Digital Wallet Works</div>".
                                                    "<ul style='padding-left:18px;color:var(--sv-text-soft);font-size:13.5px;'>".
                                                    "<li><strong>Your Referral Code:</strong> <code style='font-size:15px;font-weight:800;color:var(--sv-primary);'>".e($refCode).'</code></li>'.
                                                    "<li><strong>Your Shareable Referral Link:</strong> <code style='font-size:13.5px;'>".e($refUrl).'</code></li>'.
                                                    "<li><strong>Step 1 (Signup Bonus):</strong> When a new user registers a workspace with your code, you immediately receive <strong>+{$signupBonus} Email Credits</strong>.</li>".
                                                    "<li><strong>Step 2 (Package Purchase Reward):</strong> As soon as they purchase <em>any</em> Subscription Plan or Email Credit Package, your Digital Wallet automatically receives <strong>+{$referrerReward} Free Email Credits</strong> — and your friend receives <strong>+{$refereeBonus} Bonus Email Credits</strong>!</li>".
                                                    '</ul>'.
                                                    $referredByBanner.
                                                    '</div>'
                                                );
                                            }),

                                        TextInput::make('apply_referral_code')
                                            ->label('Have a Referral Code from a Friend? Apply It Here')
                                            ->placeholder('e.g., SV-SITEVA-9X2A')
                                            ->visible(fn (): bool => Filament::getTenant()?->referred_by_workspace_id === null)
                                            ->helperText('Enter a valid referral code before your first package purchase to unlock bonus email credits.'),

                                        Placeholder::make('referrals_table')
                                            ->label('Your Referred Workspaces & Reward Status')
                                            ->columnSpanFull()
                                            ->content(function (): HtmlString {
                                                /** @var Workspace $workspace */
                                                $workspace = Filament::getTenant();
                                                $referrals = $workspace->referralsMade()->with('referredWorkspace')->latest('id')->get();

                                                if ($referrals->isEmpty()) {
                                                    return new HtmlString('<div style="padding:12px;color:var(--sv-muted);border:1px solid var(--sv-border);border-radius:8px;">No referrals yet. Share your referral link above to start earning free System Email credits!</div>');
                                                }

                                                $rows = '';
                                                foreach ($referrals as $ref) {
                                                    $statusBadge = $ref->status === 'converted'
                                                        ? "<span style='color:var(--sv-success);font-weight:700;'>✅ Converted (Package Purchased)</span>"
                                                        : "<span style='color:var(--sv-warning);font-weight:700;'>⏳ Registered (Awaiting Purchase)</span>";
                                                    $totalEarned = (int) $ref->signup_bonus_credits + (int) $ref->purchase_reward_credits;

                                                    $rows .= "<tr style='background:var(--sv-bg);color:var(--sv-text);'>".
                                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);'><strong>".e($ref->referredWorkspace?->name ?? 'Workspace').'</strong></td>'.
                                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);'>{$statusBadge}</td>".
                                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);color:var(--sv-success);font-weight:800;'>+{$totalEarned} Credits</td>".
                                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);'>".e($ref->created_at?->format('Y-m-d H:i') ?? '').'</td>'.
                                                        '</tr>';
                                                }

                                                return new HtmlString(
                                                    "<div style='border:1px solid var(--sv-border);border-radius:10px;overflow:hidden;'>".
                                                    "<table style='width:100%;text-align:left;border-collapse:collapse;font-size:13px;'>".
                                                    "<thead><tr style='background:var(--sv-surface);color:var(--sv-text-dim);'>".
                                                    "<th style='padding:10px;'>Referred Workspace</th><th style='padding:10px;'>Status</th><th style='padding:10px;'>Your Credits Earned</th><th style='padding:10px;'>Date</th>".
                                                    "</tr></thead><tbody>{$rows}</tbody></table></div>"
                                                );
                                            }),
                                    ])
                                    ->footerActions([
                                        Action::make('applyReferralCodeAction')
                                            ->label('Apply Referral Code')
                                            ->icon(Heroicon::OutlinedGift)
                                            ->color('success')
                                            ->visible(fn (): bool => Filament::getTenant()?->referred_by_workspace_id === null)
                                            ->action(fn () => $this->applyReferralCode()),
                                    ]),
                            ]),

                        Tab::make('📜 4. Wallet Transaction Ledger')
                            ->schema([
                                Section::make('Digital Email Wallet Credit & Debit History')
                                    ->schema([
                                        Placeholder::make('wallet_ledger_table')
                                            ->hiddenLabel()
                                            ->content(function (): HtmlString {
                                                /** @var Workspace $workspace */
                                                $workspace = Filament::getTenant();
                                                $txns = $workspace->emailWalletTransactions()->latest('id')->limit(30)->get();

                                                if ($txns->isEmpty()) {
                                                    return new HtmlString('<div style="padding:12px;color:var(--sv-muted);">No wallet transactions recorded yet.</div>');
                                                }

                                                $rows = '';
                                                foreach ($txns as $tx) {
                                                    $isPositive = $tx->credits_delta >= 0;
                                                    $deltaFormatted = ($isPositive ? '+' : '').number_format($tx->credits_delta).' Credits';
                                                    $deltaColor = $isPositive ? 'var(--sv-success)' : 'var(--sv-danger)';

                                                    $rows .= "<tr style='background:var(--sv-bg);color:var(--sv-text);'>".
                                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);'>".e($tx->created_at?->format('Y-m-d H:i:s') ?? '').'</td>'.
                                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);'><code>".e($tx->type).'</code></td>'.
                                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);'>".e($tx->description).'</td>'.
                                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);font-weight:800;color:{$deltaColor};'>{$deltaFormatted}</td>".
                                                        "<td style='padding:10px;border-bottom:1px solid var(--sv-border);font-weight:700;'>".number_format($tx->balance_after).' Credits</td>'.
                                                        '</tr>';
                                                }

                                                return new HtmlString(
                                                    "<div style='border:1px solid var(--sv-border);border-radius:10px;overflow:hidden;'>".
                                                    "<table style='width:100%;text-align:left;border-collapse:collapse;font-size:13px;'>".
                                                    "<thead><tr style='background:var(--sv-surface);color:var(--sv-text-dim);'>".
                                                    "<th style='padding:10px;'>Date &amp; Time</th><th style='padding:10px;'>Type</th><th style='padding:10px;'>Description</th><th style='padding:10px;'>Change</th><th style='padding:10px;'>Balance After</th>".
                                                    "</tr></thead><tbody>{$rows}</tbody></table></div>"
                                                );
                                            }),
                                    ]),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form'),
            ]);
    }

    public function buyEmailPackage(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();
        $state = $this->data ?? [];

        $package = EmailPackage::findOrFail($state['email_package_id'] ?? null);
        $currency = strtoupper((string) ($state['currency'] ?? 'BDT')) === 'USD' ? 'USD' : 'BDT';
        $gateway = PaymentGateway::tryFrom((string) ($state['gateway'] ?? 'sslcommerz')) ?? PaymentGateway::SslCommerz;

        $basePrice = $package->getPriceForCurrency($currency);
        $taxPercent = (float) SystemSettingsService::get('trial_billing', 'tax_percentage', 0.0);
        $taxAmount = round($basePrice * ($taxPercent / 100), 2);
        $totalAmount = round($basePrice + $taxAmount, 2);

        $txId = 'EMAIL-'.strtoupper($gateway->value).'-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6));
        $invoiceNumber = 'INV-EML-'.now()->utc()->format('Ymd').'-'.strtoupper(Str::random(6));

        $payment = Payment::create([
            'workspace_id' => $workspace->id,
            'subscription_id' => $workspace->currentSubscription?->id,
            'invoice_number' => $invoiceNumber,
            'gateway' => $gateway,
            'gateway_transaction_id' => $txId,
            'idempotency_key' => 'email_pkg_'.$txId,
            'currency' => $currency,
            'amount' => $totalAmount,
            'discount_amount' => 0,
            'tax_amount' => $taxAmount,
            'status' => PaymentStatus::Pending,
            'gateway_payload' => [
                'purchase_type' => 'email_package',
                'email_package_id' => $package->id,
                'email_package_code' => $package->code,
                'email_package_name' => $package->name,
                'credits_amount' => $package->credits_amount,
                'bonus_credits' => $package->bonus_credits,
                'total_credits' => $package->totalCredits(),
                'initiated_by_user_id' => auth()->id(),
            ],
        ]);

        if (! app()->runningUnitTests()) {
            $gatewayUrl = match ($gateway) {
                PaymentGateway::SslCommerz => BillingCheckoutController::initiateSslCommerzGatewayUrl($payment, null, BillingCycle::Monthly),
                PaymentGateway::Stripe => BillingCheckoutController::initiateStripeGatewayUrl($payment, null, BillingCycle::Monthly),
                PaymentGateway::PayPal => BillingCheckoutController::initiatePayPalGatewayUrl($payment, null, BillingCycle::Monthly),
                default => null,
            };

            if (! empty($gatewayUrl)) {
                $this->redirect($gatewayUrl);

                return;
            }
        }

        $this->redirect(route('billing.checkout.show', ['paymentUuid' => $payment->invoice_number]));
    }

    public function saveSmtpSettings(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();
        $state = $this->data ?? [];

        $mode = ($state['email_provider_mode'] ?? 'system_wallet') === 'custom_smtp'
            ? 'custom_smtp'
            : 'system_wallet';

        $smtpConfig = [
            'host' => trim((string) ($state['smtp_host'] ?? '')),
            'port' => (int) ($state['smtp_port'] ?? 587),
            'encryption' => (string) ($state['smtp_encryption'] ?? 'tls'),
            'username' => trim((string) ($state['smtp_username'] ?? '')),
            'password' => (string) ($state['smtp_password'] ?? ''),
            'from_address' => trim((string) ($state['smtp_from_address'] ?? '')),
            'from_name' => trim((string) ($state['smtp_from_name'] ?? $workspace->name)),
        ];

        if ($mode === 'custom_smtp' && ($smtpConfig['host'] === '' || $smtpConfig['from_address'] === '')) {
            Notification::make()
                ->title('Missing Custom SMTP Host or From Email Address')
                ->body('Please enter your SMTP Host and From Email Address to use Custom SMTP mode.')
                ->danger()
                ->send();

            return;
        }

        $workspace->update([
            'email_provider_mode' => $mode,
            'custom_smtp_config' => $smtpConfig,
        ]);

        AuditLogService::log(
            event: 'workspace.custom_smtp.updated',
            auditable: $workspace,
            metadata: ['mode' => $mode, 'host' => $smtpConfig['host']],
            workspaceId: $workspace->id
        );

        Notification::make()
            ->title('✅ Email Delivery Mode & Custom SMTP Saved!')
            ->body($mode === 'custom_smtp'
                ? 'Your workspace will now send backup alert emails via your own Custom SMTP server for free (0 wallet credits).'
                : 'Your workspace will now send backup alert emails via SiteVaulta System Email using your Digital Email Wallet credits.')
            ->success()
            ->send();
    }

    public function sendCustomSmtpTestEmail(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();
        $state = $this->data ?? [];

        $recipient = trim((string) ($state['test_recipient_email'] ?: auth()->user()?->email));
        $smtpConfig = [
            'host' => trim((string) ($state['smtp_host'] ?? '')),
            'port' => (int) ($state['smtp_port'] ?? 587),
            'encryption' => (string) ($state['smtp_encryption'] ?? 'tls'),
            'username' => trim((string) ($state['smtp_username'] ?? '')),
            'password' => (string) ($state['smtp_password'] ?? ''),
            'from_address' => trim((string) ($state['smtp_from_address'] ?? '')),
            'from_name' => trim((string) ($state['smtp_from_name'] ?? $workspace->name)),
        ];

        if ($smtpConfig['host'] === '' || $recipient === '') {
            Notification::make()
                ->title('Please fill in SMTP Host and Test Recipient Email first.')
                ->warning()
                ->send();

            return;
        }

        try {
            EmailWalletService::sendEmailViaCustomSmtp(
                workspace: $workspace,
                recipientEmail: $recipient,
                subject: "[{$workspace->name}] Custom SMTP Diagnostic Test",
                htmlBody: "<p>Your Custom SMTP configuration for workspace <strong>".e($workspace->name)."</strong> is working properly!</p><p>Emails sent using your own SMTP cost <strong>0 Digital Wallet Credits</strong>.</p>",
                overrideConfig: $smtpConfig
            );

            Notification::make()
                ->title("✅ Test Email Sent via Your Custom SMTP to {$recipient}!")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Custom SMTP Test Failed: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function applyReferralCode(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();
        $state = $this->data ?? [];
        $code = trim((string) ($state['apply_referral_code'] ?? ''));

        if ($code === '') {
            Notification::make()->title('Please enter a referral code.')->warning()->send();

            return;
        }

        $referral = EmailWalletService::applyReferralCodeToWorkspace($workspace, $code, auth()->user());
        if (! $referral) {
            Notification::make()
                ->title('Invalid or Self Referral Code')
                ->body('Could not apply this referral code. Make sure it belongs to another workspace and has not already been applied.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('🎁 Referral Code Applied Successfully!')
            ->body('When you purchase your first Subscription Plan or Email Package, both you and your referrer will automatically receive bonus Email Wallet credits!')
            ->success()
            ->send();
    }
}

