<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Expired = 'expired';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Free Trial Active',
            self::Active => 'Active',
            self::PastDue => 'Past Due (Grace Period)',
            self::Expired => 'Expired (Backups Paused)',
            self::Canceled => 'Canceled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Trialing => 'info',
            self::Active => 'success',
            self::PastDue => 'warning',
            self::Expired, self::Canceled => 'danger',
        };
    }

    public function allowsBackupExecution(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

