<?php

namespace App\Enums;

enum BackupStrategy: string
{
    case FullOnly = 'full_only';
    case IncrementalWithPeriodicFull = 'incremental_with_periodic_full';

    public function label(): string
    {
        return match ($this) {
            self::FullOnly => 'Full Backup Every Time',
            self::IncrementalWithPeriodicFull => 'Incremental Backup with Periodic Full Reset (Recommended)',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

