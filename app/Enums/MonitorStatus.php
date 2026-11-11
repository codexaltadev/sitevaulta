<?php

namespace App\Enums;

enum MonitorStatus: string
{
    case Pending = 'pending';
    case Up = 'up';
    case Down = 'down';
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Up => 'Up',
            self::Down => 'Down',
            self::Paused => 'Paused',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Up => 'success',
            self::Down => 'danger',
            self::Paused => 'warning',
        };
    }

    /**
     * Sort weight for the "Down first" ordering used on the monitors list.
     */
    public function downFirstWeight(): int
    {
        return match ($this) {
            self::Down => 0,
            self::Pending => 1,
            self::Up => 2,
            self::Paused => 3,
        };
    }
}
