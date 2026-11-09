<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannelType;
use App\Models\BackupPolicy;
use App\Models\BackupRun;
use App\Models\EmailTemplate;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Billing\EmailWalletService;
use App\Services\Settings\SystemSettingsService;
use App\Services\Settings\WorkspaceSettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WorkspaceAlertDispatcher
{
    public static function dispatch(Workspace $workspace, string $eventCode, array $variables = []): void
    {
        if (self::isQuietHoursMuted($workspace, $eventCode)) {
            return;
        }

        SystemSettingsService::injectRuntimeConfig();

        $channels = $workspace->notificationChannels()
            ->where('is_active', true)
            ->get();

        $template = EmailTemplate::where('code', $eventCode)
            ->where('is_active', true)
            ->first();

        $subject = $template
            ? $template->renderSubject($variables)
            : "[SiteVaulta] Notification: {$eventCode}";

        $htmlMessage = $template
            ? $template->renderBody($variables)
            : '<pre>'.e((string) json_encode($variables, JSON_PRETTY_PRINT)).'</pre>';

        $plainMessage = $template
            ? strip_tags($htmlMessage)
            : (string) json_encode($variables);

        foreach ($channels as $channel) {
            $subscribedEvents = $channel->events ?? [];
            if (! empty($subscribedEvents) && ! in_array($eventCode, $subscribedEvents, true)) {
                continue;
            }

            $config = $channel->config ?? [];

            try {
                match ($channel->type) {
                    NotificationChannelType::Email => self::dispatchWorkspaceEmail(
                        $workspace,
                        (string) ($config['email'] ?? ''),
                        $subject,
                        $htmlMessage,
                        "Channel '{$channel->name}' ({$eventCode}) alert sent to " . ($config['email'] ?? '')
                    ),
                    NotificationChannelType::Slack, NotificationChannelType::Discord, NotificationChannelType::Webhook => self::sendWebhook($config['webhook_url'] ?? '', [
                        'text' => "*{$subject}*\n{$plainMessage}",
                        'content' => "**{$subject}**\n{$plainMessage}",
                        'event' => $eventCode,
                        'workspace' => $workspace->name,
                        'data' => $variables,
                    ]),
                    NotificationChannelType::Telegram => self::sendTelegram(
                        (string) ($config['bot_token'] ?? ''),
                        (string) ($config['chat_id'] ?? ''),
                        "{$subject}\n\n{$plainMessage}"
                    ),
                    default => Log::info("SiteVaulta Alert [{$eventCode}] for Workspace [{$workspace->slug}]: {$subject}"),
                };
            } catch (\Throwable $e) {
                Log::warning("Notification channel [{$channel->id}] dispatch error: ".$e->getMessage());
            }
        }
    }

    public static function sendPolicyRunEmail(
        BackupPolicy $policy,
        BackupRun $run,
        bool $isSuccess,
        ?string $errorMessage = null
    ): bool {
        $recipientEmail = trim((string) ($run->notification_email ?: $policy->notification_email));
        if ($recipientEmail === '') {
            return false;
        }

        $notifyOnSuccess = (bool) ($run->notify_on_success ?? $policy->notify_on_success ?? true);
        $notifyOnFailure = (bool) ($run->notify_on_failure ?? $policy->notify_on_failure ?? true);

        if ($isSuccess && ! $notifyOnSuccess) {
            return false;
        }

        if (! $isSuccess && ! $notifyOnFailure) {
            return false;
        }

        SystemSettingsService::injectRuntimeConfig();

        $websiteName = $policy->website?->name ?? 'Website';
        $domain = $policy->website?->domain ?? '';
        $eventCode = $isSuccess ? 'backup_succeeded' : 'backup_failed';

        $variables = [
            'website_name' => $websiteName,
            'domain' => $domain,
            'policy_name' => $policy->name,
            'run_id' => (string) $run->id,
            'run_uuid' => (string) $run->uuid,
            'backup_type' => strtoupper((string) $run->backup_type),
            'scope' => $run->scope?->value ?? (string) $policy->scope?->value,
            'archive_size' => $run->formattedSize(),
            'status' => $run->status?->value ?? ($isSuccess ? 'completed' : 'failed'),
            'error_message' => $errorMessage ?? ($run->error_message ?? 'Unknown error'),
            'completed_at' => ($run->completed_at ?? now()->utc())->format('Y-m-d H:i:s \U\T\C'),
        ];

        $template = EmailTemplate::where('code', $eventCode)
            ->where('is_active', true)
            ->first();

        if ($template) {
            $subject = $template->renderSubject($variables);
            $htmlBody = $template->renderBody($variables);
        } elseif ($isSuccess) {
            $subject = "[SiteVaulta] Backup Succeeded for {$websiteName} ({$domain})";
            $htmlBody = sprintf(
                '<p>Backup run <strong>#%s</strong> (<code>%s</code>) for <strong>%s</strong> (%s) under policy <strong>%s</strong> completed and verified successfully.</p>'
                .'<ul>'
                .'<li><strong>Chain Type:</strong> %s</li>'
                .'<li><strong>Scope:</strong> %s</li>'
                .'<li><strong>Archive Size:</strong> %s</li>'
                .'<li><strong>Completed At:</strong> %s</li>'
                .'</ul>',
                e($variables['run_id']),
                e($variables['run_uuid']),
                e($variables['website_name']),
                e($variables['domain']),
                e($variables['policy_name']),
                e($variables['backup_type']),
                e($variables['scope']),
                e($variables['archive_size']),
                e($variables['completed_at'])
            );
        } else {
            $subject = "[SiteVaulta] ALERT: Backup Failed for {$websiteName} ({$domain})";
            $htmlBody = sprintf(
                '<p>A backup run (<strong>#%s</strong>) for <strong>%s</strong> (%s) under policy <strong>%s</strong> failed.</p>'
                .'<p><strong>Diagnostic Error:</strong> %s</p>',
                e($variables['run_id']),
                e($variables['website_name']),
                e($variables['domain']),
                e($variables['policy_name']),
                e($variables['error_message'])
            );
        }

        try {
            $workspace = $policy->workspace;
            if (! $workspace) {
                return false;
            }

            $preferredMode = self::resolveEmailProviderMode($workspace, $policy, $run);

            $result = self::dispatchEmailWithFallback(
                workspace: $workspace,
                recipientEmail: $recipientEmail,
                subject: $subject,
                htmlBody: $htmlBody,
                preferredMode: $preferredMode,
                ledgerDescription: "System Email ({$eventCode}) sent to {$recipientEmail} — Policy '{$policy->name}' (Run #{$run->id})",
                reference: $run,
                metadata: [
                    'recipient' => $recipientEmail,
                    'event' => $eventCode,
                    'policy_id' => $policy->id,
                    'run_id' => $run->id,
                ]
            );

            if (! $result['sent']) {
                return false;
            }

            $run->update([
                'email_provider_mode' => $result['provider_used'] ?: $preferredMode,
                'notification_sent_at' => now()->utc(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning("BackupPolicy [{$policy->id}] run [{$run->id}] email dispatch error: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Sends an email using the user's preferred delivery mode ('custom_smtp' or 'system_wallet'),
     * and automatically falls back to whichever delivery method is available if the preferred one is unavailable!
     *
     * @return array{sent: bool, provider_used: string|null}
     */
    public static function dispatchEmailWithFallback(
        Workspace $workspace,
        string $recipientEmail,
        string $subject,
        string $htmlBody,
        string $preferredMode = 'workspace_default',
        string $ledgerDescription = 'System Email Alert',
        ?Model $reference = null,
        array $metadata = []
    ): array {
        $recipientEmail = trim($recipientEmail);
        if ($recipientEmail === '') {
            return ['sent' => false, 'provider_used' => null];
        }

        $workspace->refresh();

        $effectivePreferred = in_array($preferredMode, ['custom_smtp', 'system_wallet'], true)
            ? $preferredMode
            : ($workspace->email_provider_mode === 'custom_smtp' ? 'custom_smtp' : 'system_wallet');

        $order = $effectivePreferred === 'custom_smtp'
            ? ['custom_smtp', 'system_wallet']
            : ['system_wallet', 'custom_smtp'];

        foreach ($order as $candidate) {
            if ($candidate === 'custom_smtp') {
                if (! $workspace->hasValidCustomSmtp()) {
                    continue;
                }

                try {
                    EmailWalletService::sendEmailViaCustomSmtp($workspace, $recipientEmail, $subject, $htmlBody);

                    return ['sent' => true, 'provider_used' => 'custom_smtp'];
                } catch (\Throwable $e) {
                    Log::warning("Custom SMTP failed for workspace [{$workspace->slug}], attempting fallback: ".$e->getMessage());

                    continue;
                }
            }

            if ($candidate === 'system_wallet') {
                if ((int) $workspace->email_credits_balance < 1) {
                    continue;
                }

                try {
                    SystemSettingsService::injectRuntimeConfig();
                    self::sendEmail($recipientEmail, $subject, $htmlBody);

                    EmailWalletService::debitWalletForEmail(
                        workspace: $workspace,
                        description: $ledgerDescription,
                        reference: $reference,
                        metadata: $metadata
                    );

                    return ['sent' => true, 'provider_used' => 'system_wallet'];
                } catch (\Throwable $e) {
                    Log::warning("System Email Wallet send failed for workspace [{$workspace->slug}], attempting fallback: ".$e->getMessage());

                    continue;
                }
            }
        }

        Log::warning("Workspace [{$workspace->slug}] could not send email to [{$recipientEmail}]: neither Custom SMTP nor System Email Wallet credits were available.");

        return ['sent' => false, 'provider_used' => null];
    }

    public static function sendWebsiteUptimeAlertEmail(
        Website $website,
        bool $isDown,
        string $reason,
        ?int $httpStatus = null,
        ?int $responseTimeMs = null
    ): bool {
        $workspace = $website->workspace;
        if (! $workspace) {
            return false;
        }

        if ($isDown && ! ($website->notify_on_downtime ?? true)) {
            return false;
        }

        if (! $isDown && ! ($website->notify_on_recovery ?? true)) {
            return false;
        }

        $recipientEmail = trim((string) ($website->uptime_alert_email ?: $workspace->owner?->email));
        if ($recipientEmail === '') {
            return false;
        }

        $checkedAt = now()->utc()->format('Y-m-d H:i:s \U\T\C');

        if ($isDown) {
            $subject = "[SiteVaulta] 🚨 DOWN ALERT: {$website->name} ({$website->domain}) is Unreachable!";
            $htmlBody = sprintf(
                '<p><strong>🚨 Critical Uptime Alert:</strong> Your verified website <strong>%s</strong> (<code>%s</code>) or its connected server is currently unreachable.</p>'
                .'<ul>'
                .'<li><strong>Website URL:</strong> %s</li>'
                .'<li><strong>Diagnostic Reason:</strong> <span style="color:#dc2626;font-weight:bold;">%s</span></li>'
                .'<li><strong>HTTP Status Code:</strong> %s</li>'
                .'<li><strong>Detected At:</strong> %s</li>'
                .'</ul>'
                .'<p>Please check your domain DNS, web server, or server connection immediately.</p>',
                e($website->name),
                e($website->domain),
                e($website->url),
                e($reason),
                e($httpStatus ? (string) $httpStatus : 'No Response / Timeout'),
                e($checkedAt)
            );
            $eventLabel = 'website_down';
        } else {
            $subject = "[SiteVaulta] ✅ RECOVERED: {$website->name} ({$website->domain}) is Back Online!";
            $htmlBody = sprintf(
                '<p><strong>✅ Website &amp; Server Recovered:</strong> Your website <strong>%s</strong> (<code>%s</code>) is back online and responding normally.</p>'
                .'<ul>'
                .'<li><strong>Website URL:</strong> %s</li>'
                .'<li><strong>HTTP Status Code:</strong> %s</li>'
                .'<li><strong>Response Time:</strong> %s ms</li>'
                .'<li><strong>Verified At:</strong> %s</li>'
                .'</ul>',
                e($website->name),
                e($website->domain),
                e($website->url),
                e((string) ($httpStatus ?: 200)),
                e((string) ($responseTimeMs ?: 0)),
                e($checkedAt)
            );
            $eventLabel = 'website_recovered';
        }

        $result = self::dispatchEmailWithFallback(
            workspace: $workspace,
            recipientEmail: $recipientEmail,
            subject: $subject,
            htmlBody: $htmlBody,
            preferredMode: $website->uptime_email_provider_mode ?: 'workspace_default',
            ledgerDescription: "Uptime Alert ({$eventLabel}) sent to {$recipientEmail} for {$website->domain}",
            reference: $website,
            metadata: [
                'website_id' => $website->id,
                'domain' => $website->domain,
                'event' => $eventLabel,
                'reason' => $reason,
            ]
        );

        return $result['sent'];
    }

    public static function resolveEmailProviderMode(?Workspace $workspace, ?BackupPolicy $policy = null, ?BackupRun $run = null): string
    {
        $runMode = $run?->email_provider_mode;
        if (in_array($runMode, ['custom_smtp', 'system_wallet'], true)) {
            return $runMode;
        }

        $policyMode = $policy?->email_provider_mode;
        if (in_array($policyMode, ['custom_smtp', 'system_wallet'], true)) {
            return $policyMode;
        }

        $workspaceMode = $workspace?->email_provider_mode;

        return $workspaceMode === 'custom_smtp' ? 'custom_smtp' : 'system_wallet';
    }

    protected static function dispatchWorkspaceEmail(
        Workspace $workspace,
        string $recipientEmail,
        string $subject,
        string $htmlBody,
        string $ledgerDescription
    ): void {
        self::dispatchEmailWithFallback(
            workspace: $workspace,
            recipientEmail: $recipientEmail,
            subject: $subject,
            htmlBody: $htmlBody,
            preferredMode: $workspace->email_provider_mode ?: 'system_wallet',
            ledgerDescription: $ledgerDescription
        );
    }

    protected static function sendEmail(string $recipientEmail, string $subject, string $htmlBody): void
    {
        if ($recipientEmail !== '') {
            Mail::html($htmlBody, function ($message) use ($recipientEmail, $subject): void {
                $message->to($recipientEmail)->subject($subject);
            });
        }
    }

    public static function isQuietHoursMuted(Workspace $workspace, string $eventCode): bool
    {
        // Critical failure alerts bypass quiet hours so ops teams are never left blind
        if ($eventCode === 'backup_failed') {
            return false;
        }

        $enabled = (bool) WorkspaceSettingsService::get($workspace, 'notifications', 'quiet_hours_enabled', false);
        if (! $enabled) {
            return false;
        }

        $start = (string) WorkspaceSettingsService::get($workspace, 'notifications', 'quiet_hours_start', '23:00');
        $end = (string) WorkspaceSettingsService::get($workspace, 'notifications', 'quiet_hours_end', '07:00');
        $timezone = $workspace->timezone ?: (string) WorkspaceSettingsService::get($workspace, 'regional', 'timezone', 'UTC');

        try {
            $currentHm = now($timezone)->format('H:i');
        } catch (\Throwable) {
            $currentHm = now()->format('H:i');
        }

        if ($start <= $end) {
            return $currentHm >= $start && $currentHm <= $end;
        }

        // Overnight window (e.g., 23:00 to 07:00)
        return $currentHm >= $start || $currentHm <= $end;
    }

    protected static function sendWebhook(string $url, array $payload): void
    {
        if ($url !== '') {
            Http::timeout(5)->post($url, $payload);
        }
    }

    protected static function sendTelegram(string $botToken, string $chatId, string $text): void
    {
        if ($botToken !== '' && $chatId !== '') {
            Http::timeout(5)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        }
    }
}
