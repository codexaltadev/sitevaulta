<?php

namespace App\Enums;

enum MonitorType: string
{
    case Http = 'http';
    case Keyword = 'keyword';
    case Port = 'port';
    case Ping = 'ping';
    case Heartbeat = 'heartbeat';
    case Dns = 'dns';
    case Ssl = 'ssl';
    case Domain = 'domain';

    public function label(): string
    {
        return match ($this) {
            self::Http => 'HTTP(s)',
            self::Keyword => 'Keyword',
            self::Port => 'Port',
            self::Ping => 'Ping',
            self::Heartbeat => 'Heartbeat (Cron job)',
            self::Dns => 'DNS record',
            self::Ssl => 'SSL certificate expiry',
            self::Domain => 'Domain expiry',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Http => 'HTTP',
            self::Keyword => 'KEYWORD',
            self::Port => 'PORT',
            self::Ping => 'PING',
            self::Heartbeat => 'HEARTBEAT',
            self::Dns => 'DNS',
            self::Ssl => 'SSL',
            self::Domain => 'DOMAIN',
        };
    }

    /**
     * HTTP and Keyword monitors request a full URL.
     */
    public function usesUrl(): bool
    {
        return in_array($this, [self::Http, self::Keyword], true);
    }

    /**
     * Monitors that target a bare hostname or IP.
     */
    public function usesHost(): bool
    {
        return in_array($this, [self::Port, self::Ping, self::Dns, self::Ssl, self::Domain], true);
    }

    /**
     * Heartbeat monitors wait for the target to call us instead of calling it.
     */
    public function isPassive(): bool
    {
        return $this === self::Heartbeat;
    }

    /**
     * Monitors that alert ahead of an expiry date instead of on reachability.
     */
    public function isExpiryCheck(): bool
    {
        return in_array($this, [self::Ssl, self::Domain], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
