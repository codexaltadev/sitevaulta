<?php

namespace App\Enums;

enum BillingCycle: string
{
    case Trial = 'trial';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Lifetime = 'lifetime';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Free Trial',
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
            self::Lifetime => 'Lifetime',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

