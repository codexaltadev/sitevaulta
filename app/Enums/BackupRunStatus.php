<?php

namespace App\Enums;

enum BackupRunStatus: string
{
    case Queued = 'queued';
    case Connecting = 'connecting';
    case Extracting = 'extracting';
    case Packaging = 'packaging';
    case Uploading = 'uploading';
    case Verifying = 'verifying';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Connecting => 'Connecting to Server',
            self::Extracting => 'Extracting Files & DB',
            self::Packaging => 'Compressing & Encrypting',
            self::Uploading => 'Uploading to Storage',
            self::Verifying => 'Verifying SHA-256 Checksum',
            self::Completed => 'Verified & Completed',
            self::Failed => 'Failed',
            self::Canceled => 'Canceled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'gray',
            self::Connecting, self::Extracting, self::Packaging, self::Uploading, self::Verifying => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Canceled => 'warning',
        };
    }

    public function isInProgress(): bool
    {
        return in_array($this, [
            self::Queued,
            self::Connecting,
            self::Extracting,
            self::Packaging,
            self::Uploading,
            self::Verifying,
        ], true);
    }

    public function progressPercent(): int
    {
        return match ($this) {
            self::Queued => 5,
            self::Connecting => 15,
            self::Extracting => 40,
            self::Packaging => 65,
            self::Uploading => 85,
            self::Verifying => 95,
            self::Completed => 100,
            default => 0,
        };
    }

    public function stageDescription(): string
    {
        return match ($this) {
            self::Queued => 'Queued in server worker...',
            self::Connecting => 'Connecting to server & database...',
            self::Extracting => 'Extracting website files & database...',
            self::Packaging => 'Compressing & encrypting (AES-256-GCM)...',
            self::Uploading => 'Streaming upload to cloud storage...',
            self::Verifying => 'Verifying SHA-256 integrity checksum...',
            self::Completed => 'Verified & completed successfully',
            self::Failed => 'Execution failed',
            self::Canceled => 'Run was canceled',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

