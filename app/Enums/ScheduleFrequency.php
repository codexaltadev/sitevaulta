<?php

namespace App\Enums;

enum ScheduleFrequency: string
{
    case Hourly = 'hourly';
    case EverySixHours = 'every_6h';
    case EveryTwelveHours = 'every_12h';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case CustomCron = 'custom_cron';

    public function label(): string
    {
        return match ($this) {
            self::Hourly => 'Every Hour (Hourly)',
            self::EverySixHours => 'Every 6 Hours (4x Daily)',
            self::EveryTwelveHours => 'Every 12 Hours (2x Daily)',
            self::Daily => 'Once Daily',
            self::Weekly => 'Once Weekly',
            self::CustomCron => 'Custom Interval / Cron',
        };
    }

    public function defaultIntervalMinutes(): int
    {
        return match ($this) {
            self::Hourly => 60,
            self::EverySixHours => 360,
            self::EveryTwelveHours => 720,
            self::Daily => 1440,
            self::Weekly => 10080,
            self::CustomCron => 1440,
        };
    }

    public function defaultCronExpression(): string
    {
        return match ($this) {
            self::Hourly => '0 * * * *',
            self::EverySixHours => '0 */6 * * *',
            self::EveryTwelveHours => '0 */12 * * *',
            self::Daily => '0 2 * * *',
            self::Weekly => '0 2 * * 0',
            self::CustomCron => '0 2 * * *',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

