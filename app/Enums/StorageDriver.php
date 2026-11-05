<?php

namespace App\Enums;

enum StorageDriver: string
{
    case GoogleDrive = 'google_drive';
    case S3 = 's3';
    case CloudflareR2 = 'cloudflare_r2';
    case Sftp = 'sftp';
    case S3Compatible = 's3_compatible';

    public function label(): string
    {
        return match ($this) {
            self::GoogleDrive => 'Google Drive',
            self::S3 => 'AWS S3',
            self::CloudflareR2 => 'Cloudflare R2',
            self::Sftp => 'VPS / SFTP Server',
            self::S3Compatible => 'S3-Compatible (DigitalOcean Spaces / Wasabi / Backblaze B2)',
        };
    }

    public function supportsPresignedUrls(): bool
    {
        return in_array($this, [self::S3, self::CloudflareR2, self::S3Compatible], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
