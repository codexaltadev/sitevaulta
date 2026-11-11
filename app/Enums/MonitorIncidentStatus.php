<?php

namespace App\Enums;

enum MonitorIncidentStatus: string
{
    case Ongoing = 'ongoing';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Ongoing => 'Ongoing',
            self::Resolved => 'Resolved',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ongoing => 'danger',
            self::Resolved => 'success',
        };
    }
}
