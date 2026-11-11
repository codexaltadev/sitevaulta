<?php

namespace App\Enums;

enum MaintenanceScheduleType: string
{
    case OneTime = 'one_time';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::OneTime => 'One-time',
            self::Daily => 'Every day',
            self::Weekly => 'Every week',
            self::Monthly => 'Every month',
        };
    }

    public function isRecurring(): bool
    {
        return $this !== self::OneTime;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
