<?php

namespace App\Enums;

enum NotificationChannelType: string
{
    case Email = 'email';
    case Slack = 'slack';
    case Discord = 'discord';
    case Telegram = 'telegram';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email Notification',
            self::Slack => 'Slack Incoming Webhook',
            self::Discord => 'Discord Channel Webhook',
            self::Telegram => 'Telegram Bot Alert',
            self::Webhook => 'Custom HTTP Webhook (JSON)',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

