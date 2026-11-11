<?php

namespace App\Models;

use Database\Factories\ProbeLocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A probe agent in another region (this application running with MONITORING_AGENT_MODE=true). It re-checks
 * a failing monitor so a site is only marked down when most locations agree.
 */
class ProbeLocation extends Model
{
    /** @use HasFactory<ProbeLocationFactory> */
    use HasFactory;

    public const HEALTHY = 'healthy';

    public const UNREACHABLE = 'unreachable';

    protected $fillable = [
        'name',
        'region',
        'url',
        'secret',
        'public_ips',
        'is_enabled',
        'health_status',
        'last_seen_at',
        'last_error',
    ];

    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'public_ips' => 'array',
            'is_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $location): void {
            $location->url = rtrim((string) $location->url, '/');
            $location->secret = filled($location->secret) ? $location->secret : self::generateSecret();
        });
    }

    public static function generateSecret(): string
    {
        return Str::random(48);
    }

    /**
     * Enabled agents that are not known to be down; an agent that failed its last health check is skipped
     * so a dead agent never slows down or blocks a verdict.
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_enabled', true)->where('health_status', '!=', self::UNREACHABLE);
    }

    public function endpoint(string $action): string
    {
        return "{$this->url}/probe-agent/{$action}";
    }
}
