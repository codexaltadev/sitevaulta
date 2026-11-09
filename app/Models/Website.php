<?php

namespace App\Models;

use App\Enums\VerificationMethod;
use App\Enums\WebsiteVerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Website extends Model
{
    protected $fillable = [
        'uuid',
        'workspace_id',
        'name',
        'url',
        'domain',
        'environment',
        'verification_status',
        'verified_at',
        'verification_method',
        'webhook_secret_hash',
        'known_files_size_bytes',
        'known_db_size_bytes',
        'status',
        'uptime_monitoring_enabled',
        'uptime_status',
        'last_http_status_code',
        'last_response_time_ms',
        'server_live_status',
        'last_uptime_checked_at',
        'last_down_reason',
        'uptime_alert_email',
        'uptime_email_provider_mode',
        'notify_on_downtime',
        'notify_on_recovery',
        'last_downtime_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'verification_status' => WebsiteVerificationStatus::class,
            'verification_method' => VerificationMethod::class,
            'verified_at' => 'datetime',
            'known_files_size_bytes' => 'integer',
            'known_db_size_bytes' => 'integer',
            'uptime_monitoring_enabled' => 'boolean',
            'last_http_status_code' => 'integer',
            'last_response_time_ms' => 'integer',
            'last_uptime_checked_at' => 'datetime',
            'notify_on_downtime' => 'boolean',
            'notify_on_recovery' => 'boolean',
            'last_downtime_notified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $website): void {
            if (empty($website->uuid)) {
                $website->uuid = (string) Str::uuid();
            }
            if (empty($website->domain) && ! empty($website->url)) {
                $website->domain = self::extractDomain($website->url);
            }
        });

        static::updating(function (self $website): void {
            if ($website->isDirty('url')) {
                $website->domain = self::extractDomain($website->url);
            }
        });
    }

    public static function extractDomain(string $url): string
    {
        if (! str_contains($url, '://')) {
            $url = 'https://' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        return Str::lower(trim($host, '/'));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(WebsiteVerification::class);
    }

    public function latestVerification(): HasOne
    {
        return $this->hasOne(WebsiteVerification::class)->latestOfMany();
    }

    public function serverConnections(): HasMany
    {
        return $this->hasMany(ServerConnection::class);
    }

    public function backupPolicies(): HasMany
    {
        return $this->hasMany(BackupPolicy::class);
    }

    public function backupRuns(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    public function isVerified(): bool
    {
        return $this->verification_status === WebsiteVerificationStatus::Verified;
    }
}

