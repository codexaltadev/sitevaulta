<?php

namespace App\Services\Billing;

use App\Models\EmailPackage;
use App\Models\EmailWalletTransaction;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use App\Services\Security\AuditLogService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailWalletService
{
    public static function ensureReferralCode(Workspace $workspace): string
    {
        if (! empty($workspace->referral_code)) {
            return $workspace->referral_code;
        }

        $prefix = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $workspace->slug ?: 'SV'), 0, 6));
        $code = 'SV-' . ($prefix ?: 'USER') . '-' . Str::upper(Str::random(4));

        while (Workspace::where('referral_code', $code)->where('id', '!=', $workspace->id)->exists()) {
            $code = 'SV-' . ($prefix ?: 'USER') . '-' . Str::upper(Str::random(4));
        }

        $workspace->update(['referral_code' => $code]);

        return $code;
    }

    public static function initializeWelcomeCredits(Workspace $workspace): void
    {
        self::ensureReferralCode($workspace);

        if ($workspace->emailWalletTransactions()->where('type', 'welcome_bonus')->exists()) {
            return;
        }

        $welcomeCredits = (int) SystemSettingsService::get('email_wallet_referral', 'welcome_free_email_credits', 50);
        if ($welcomeCredits <= 0) {
            return;
        }

        DB::transaction(function () use ($workspace, $welcomeCredits): void {
            $workspace->update(['email_credits_balance' => $welcomeCredits]);

            EmailWalletTransaction::create([
                'workspace_id' => $workspace->id,
                'type' => 'welcome_bonus',
                'credits_delta' => $welcomeCredits,
                'balance_after' => $welcomeCredits,
                'description' => "Welcome Starter Bonus: +{$welcomeCredits} Free System Email Credits",
            ]);
        });
    }

    public static function creditWallet(
        Workspace $workspace,
        int $credits,
        string $type,
        string $description,
        ?Model $reference = null,
        array $metadata = []
    ): EmailWalletTransaction {
        return DB::transaction(function () use ($workspace, $credits, $type, $description, $reference, $metadata): EmailWalletTransaction {
            /** @var Workspace $locked */
            $locked = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $newBalance = max(0, (int) $locked->email_credits_balance + $credits);

            $locked->update(['email_credits_balance' => $newBalance]);
            $workspace->email_credits_balance = $newBalance;

            $tx = EmailWalletTransaction::create([
                'workspace_id' => $locked->id,
                'type' => $type,
                'credits_delta' => $credits,
                'balance_after' => $newBalance,
                'description' => $description,
                'reference_type' => $reference ? class_basename($reference) : null,
                'reference_id' => $reference?->getKey(),
                'metadata' => $metadata,
            ]);

            if ($credits > 0) {
                WorkspaceAlertDispatcher::sendDatabaseNotificationToWorkspace(
                    workspace: $locked,
                    title: "💳 +{$credits} Email Credits Added to Wallet",
                    body: "{$description} (New Balance: {$newBalance} Credits)",
                    status: 'success'
                );
            }

            return $tx;
        });
    }

    public static function debitWalletForEmail(
        Workspace $workspace,
        string $description,
        ?Model $reference = null,
        array $metadata = []
    ): bool {
        return DB::transaction(function () use ($workspace, $description, $reference, $metadata): bool {
            /** @var Workspace $locked */
            $locked = Workspace::whereKey($workspace->id)->lockForUpdate()->first();
            if (! $locked || (int) $locked->email_credits_balance < 1) {
                return false;
            }

            $newBalance = (int) $locked->email_credits_balance - 1;
            $locked->update(['email_credits_balance' => $newBalance]);
            $workspace->email_credits_balance = $newBalance;

            EmailWalletTransaction::create([
                'workspace_id' => $locked->id,
                'type' => 'email_sent_deduction',
                'credits_delta' => -1,
                'balance_after' => $newBalance,
                'description' => $description,
                'reference_type' => $reference ? class_basename($reference) : null,
                'reference_id' => $reference?->getKey(),
                'metadata' => $metadata,
            ]);

            return true;
        });
    }

    public static function applyReferralCodeToWorkspace(
        Workspace $referredWorkspace,
        string $referralCode,
        ?User $referredUser = null
    ): ?Referral {
        $cleanCode = strtoupper(trim($referralCode));
        if ($cleanCode === '' || $referredWorkspace->referred_by_workspace_id !== null) {
            return null;
        }

        $referrerWorkspace = Workspace::whereRaw('UPPER(referral_code) = ?', [$cleanCode])
            ->where('id', '!=', $referredWorkspace->id)
            ->first();

        if (! $referrerWorkspace) {
            return null;
        }

        // Prevent self-referral by the same workspace owner
        if ($referrerWorkspace->owner_id && $referrerWorkspace->owner_id === $referredWorkspace->owner_id) {
            return null;
        }

        return DB::transaction(function () use ($referrerWorkspace, $referredWorkspace, $referredUser, $cleanCode): Referral {
            $referredWorkspace->update([
                'referred_by_workspace_id' => $referrerWorkspace->id,
            ]);

            $signupBonus = (int) SystemSettingsService::get('email_wallet_referral', 'referral_signup_bonus_credits', 25);

            $referral = Referral::updateOrCreate(
                [
                    'referrer_workspace_id' => $referrerWorkspace->id,
                    'referred_workspace_id' => $referredWorkspace->id,
                ],
                [
                    'referred_user_id' => $referredUser?->id ?? $referredWorkspace->owner_id,
                    'referral_code_used' => $cleanCode,
                    'status' => 'registered',
                    'signup_bonus_credits' => $signupBonus,
                ]
            );

            if ($signupBonus > 0 && $referral->wasRecentlyCreated) {
                self::creditWallet(
                    workspace: $referrerWorkspace,
                    credits: $signupBonus,
                    type: 'referral_signup_bonus',
                    description: "Referral Signup Bonus: Workspace '{$referredWorkspace->name}' registered using code {$cleanCode}",
                    reference: $referral,
                    metadata: ['referred_workspace_id' => $referredWorkspace->id]
                );
            }

            AuditLogService::log(
                event: 'referral.applied',
                auditable: $referral,
                metadata: [
                    'referrer_workspace_id' => $referrerWorkspace->id,
                    'referred_workspace_id' => $referredWorkspace->id,
                    'code' => $cleanCode,
                ],
                workspaceId: $referredWorkspace->id
            );

            return $referral;
        });
    }

    public static function processReferralRewardOnPurchase(
        Workspace $purchasingWorkspace,
        Payment $payment,
        string $purchasedItemLabel
    ): ?Referral {
        $purchasingWorkspace->refresh();

        if (! $purchasingWorkspace->referred_by_workspace_id || $purchasingWorkspace->referral_rewarded_at !== null) {
            return null;
        }

        if ((float) $payment->amount <= 0) {
            return null;
        }

        $referrerWorkspace = Workspace::find($purchasingWorkspace->referred_by_workspace_id);
        if (! $referrerWorkspace) {
            return null;
        }

        $referrerReward = (int) SystemSettingsService::get('email_wallet_referral', 'referral_purchase_referrer_credits', 500);
        $refereeBonus = (int) SystemSettingsService::get('email_wallet_referral', 'referral_purchase_referee_credits', 200);

        return DB::transaction(function () use (
            $referrerWorkspace,
            $purchasingWorkspace,
            $payment,
            $purchasedItemLabel,
            $referrerReward,
            $refereeBonus
        ): Referral {
            $purchasingWorkspace->update([
                'referral_rewarded_at' => now()->utc(),
            ]);

            $referral = Referral::updateOrCreate(
                [
                    'referrer_workspace_id' => $referrerWorkspace->id,
                    'referred_workspace_id' => $purchasingWorkspace->id,
                ],
                [
                    'referred_user_id' => $purchasingWorkspace->owner_id,
                    'referral_code_used' => $referrerWorkspace->referral_code ?: 'REFERRAL',
                    'status' => 'converted',
                    'purchase_reward_credits' => $referrerReward,
                    'referee_bonus_credits' => $refereeBonus,
                    'converted_payment_id' => $payment->id,
                    'converted_at' => now()->utc(),
                ]
            );

            if ($referrerReward > 0) {
                self::creditWallet(
                    workspace: $referrerWorkspace,
                    credits: $referrerReward,
                    type: 'referral_purchase_reward',
                    description: "🎁 Referral Purchase Reward: '{$purchasingWorkspace->name}' purchased {$purchasedItemLabel} ({$payment->invoice_number})",
                    reference: $referral,
                    metadata: [
                        'referred_workspace_id' => $purchasingWorkspace->id,
                        'payment_id' => $payment->id,
                        'invoice_number' => $payment->invoice_number,
                    ]
                );
            }

            if ($refereeBonus > 0) {
                self::creditWallet(
                    workspace: $purchasingWorkspace,
                    credits: $refereeBonus,
                    type: 'referral_purchase_reward',
                    description: "🎉 Referral Bonus Credits: Rewarded on your first purchase of {$purchasedItemLabel} ({$payment->invoice_number})",
                    reference: $referral,
                    metadata: [
                        'referrer_workspace_id' => $referrerWorkspace->id,
                        'payment_id' => $payment->id,
                    ]
                );
            }

            AuditLogService::log(
                event: 'referral.converted_and_rewarded',
                auditable: $referral,
                metadata: [
                    'referrer_workspace_id' => $referrerWorkspace->id,
                    'referred_workspace_id' => $purchasingWorkspace->id,
                    'referrer_reward_credits' => $referrerReward,
                    'referee_bonus_credits' => $refereeBonus,
                    'invoice_number' => $payment->invoice_number,
                ],
                workspaceId: $referrerWorkspace->id
            );

            return $referral;
        });
    }

    public static function hasValidCustomSmtp(Workspace $workspace): bool
    {
        $cfg = $workspace->custom_smtp_config ?? [];

        return is_array($cfg) && trim((string) ($cfg['host'] ?? '')) !== '';
    }

    public static function sendEmailViaCustomSmtp(
        Workspace $workspace,
        string $recipientEmail,
        string $subject,
        string $htmlBody,
        ?array $overrideConfig = null
    ): void {
        $cfg = $overrideConfig ?? ($workspace->custom_smtp_config ?? []);
        $host = trim((string) ($cfg['host'] ?? ''));
        $port = (int) ($cfg['port'] ?? 587);
        $encryption = (string) ($cfg['encryption'] ?? 'tls');
        $username = $cfg['username'] ?? null;
        $password = $cfg['password'] ?? null;
        $fromAddress = trim((string) ($cfg['from_address'] ?? ($workspace->owner?->email ?: 'noreply@example.com')));
        $fromName = trim((string) ($cfg['from_name'] ?? $workspace->name));

        if ($host === '') {
            throw new \RuntimeException('Custom SMTP Host is not configured for this workspace.');
        }

        config([
            'mail.mailers.workspace_custom_smtp' => [
                'transport' => 'smtp',
                'host' => $host,
                'port' => $port ?: 587,
                'encryption' => $encryption !== '' ? $encryption : null,
                'username' => $username ?: null,
                'password' => $password ?: null,
                'timeout' => 15,
            ],
        ]);

        Mail::purge('workspace_custom_smtp');

        $mailer = app()->runningUnitTests()
            ? Mail::mailer()
            : Mail::mailer('workspace_custom_smtp');

        $mailer->html($htmlBody, function ($message) use ($recipientEmail, $subject, $fromAddress, $fromName): void {
            $message->to($recipientEmail)
                ->from($fromAddress, $fromName)
                ->subject($subject);
        });
    }

    public static function seedDefaultEmailPackages(): void
    {
        $packages = [
            [
                'code' => 'email_starter_500',
                'name' => 'Starter 500 Email Pack',
                'description' => 'Ideal for personal websites and daily backup notifications.',
                'sort_order' => 1,
                'credits_amount' => 500,
                'bonus_credits' => 0,
                'price_bdt' => 190.00,
                'price_usd' => 2.00,
                'is_featured' => false,
                'is_active' => true,
            ],
            [
                'code' => 'email_growth_2500',
                'name' => 'Growth 2,500 Email Pack (+250 Bonus)',
                'description' => 'Best value for developers and growing agencies running frequent backups.',
                'sort_order' => 2,
                'credits_amount' => 2500,
                'bonus_credits' => 250,
                'price_bdt' => 690.00,
                'price_usd' => 7.00,
                'is_featured' => true,
                'is_active' => true,
            ],
            [
                'code' => 'email_agency_10000',
                'name' => 'Agency 10,000 Email Pack (+1,500 Bonus)',
                'description' => 'High-volume system email delivery for multi-client agencies.',
                'sort_order' => 3,
                'credits_amount' => 10000,
                'bonus_credits' => 1500,
                'price_bdt' => 1990.00,
                'price_usd' => 20.00,
                'is_featured' => false,
                'is_active' => true,
            ],
        ];

        foreach ($packages as $pkg) {
            EmailPackage::updateOrCreate(['code' => $pkg['code']], $pkg);
        }
    }
}

