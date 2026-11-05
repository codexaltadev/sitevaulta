<?php

namespace App\Enums;

enum DownloadMode: string
{
    case OneTimeToken = 'one_time_token';
    case PresignedUrl = 'presigned_url';

    public function label(): string
    {
        return match ($this) {
            self::OneTimeToken => 'One-Time Atomic Download Token (Most Secure)',
            self::PresignedUrl => 'Short-Lived Signed Cloud URL (S3 / R2)',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

