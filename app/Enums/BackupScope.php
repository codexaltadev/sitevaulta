<?php

namespace App\Enums;

enum BackupScope: string
{
    case FullStack = 'full_stack';
    case FilesOnly = 'files_only';
    case DatabaseOnly = 'database_only';

    public function label(): string
    {
        return match ($this) {
            self::FullStack => 'Full Website (Files + Database)',
            self::FilesOnly => 'Website Files Only',
            self::DatabaseOnly => 'Database Only',
        };
    }

    public function includesFiles(): bool
    {
        return in_array($this, [self::FullStack, self::FilesOnly], true);
    }

    public function includesDatabase(): bool
    {
        return in_array($this, [self::FullStack, self::DatabaseOnly], true);
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

