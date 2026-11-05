<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannelType;
use App\Models\EmailTemplate;
use App\Models\Workspace;
use App\Services\Settings\WorkspaceSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WorkspaceAlertDispatcher
{
    public static function dispatch(Workspace $workspace, string $eventCode, array $variables = []): void
    {
        if (self::isQuietHoursMuted($workspace, $eventCode)) {
            return;
        }

        $channels = $workspace->notificationChannels()
            ->where('is_active', true)
            ->get();

        $template = EmailTemplate::where('code', $eventCode)
            ->where('is_active', true)
            ->first();

        $subject = $template
            ? $template->renderSubject($variables)
            : "[SiteVaulta] Notification: {$eventCode}";

        $plainMessage = $template
            ? strip_tags($template->renderBody($variables))
            : json_encode($variables);

        foreach ($channels as $channel) {
            $subscribedEvents = $channel->events ?? [];
            if (! empty($subscribedEvents) && ! in_array($eventCode, $subscribedEvents, true)) {
                continue;
            }

            $config = $channel->config ?? [];

            try {
                match ($channel->type) {
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
