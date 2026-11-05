<?php

namespace App\Enums;

enum WebsiteVerificationStatus: string
{
    case Unverified = 'unverified';
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::Pending => 'Verification Pending',
            self::Verified => 'Ownership Verified',
            self::Failed => 'Verification Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unverified => 'gray',
            self::Pending => 'warning',
            self::Verified => 'success',
            self::Failed => 'danger',
        };
    }
}

