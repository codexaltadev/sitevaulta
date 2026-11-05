<?php

namespace App\Enums;

enum DatabaseDriver: string
{
    case None = 'none';
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
    case PostgreSql = 'pgsql';
    case CPanelMySql = 'cpanel_mysql';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None (Files Only)',
            self::MySql => 'MySQL Database',
            self::MariaDb => 'MariaDB Database',
            self::PostgreSql => 'PostgreSQL Database',
            self::CPanelMySql => 'cPanel Managed MySQL',
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::MySql, self::MariaDb => 3306,
            self::PostgreSql => 5432,
            default => 3306,
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

