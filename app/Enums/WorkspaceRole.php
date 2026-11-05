<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner (Full Access & Billing)',
            self::Admin => 'Admin (Manage Sites, Storage & Policies)',
            self::Operator => 'Operator (Run Backups & Download)',
            self::Viewer => 'Viewer (Read-Only Reports & Status)',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

