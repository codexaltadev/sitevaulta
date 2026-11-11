<?php

namespace App\Models;

use App\Enums\WebsiteVerificationStatus;
use App\Services\Monitoring\VerifiedDomainSync;
use App\Services\Monitoring\WebsiteMonitorSync;
use Illuminate\Database\Eloquent\Builder;
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
        'webhook_secret_hash',
        'known_files_size_bytes',
        'known_db_size_bytes',
        'status',
        'uptime_monitoring_enabled',
        'uptime_status',
        'last_http_status_code',
        'last_response_time_ms',
        'server_live_status',
        'ssl_status',
        'ssl_issuer',
        'ssl_expires_at',
        'ssl_days_remaining',
        'last_uptime_checked_at',
        'last_down_reason',
        'uptime_alert_email',
        'uptime_email_provider_mode',
        'notify_on_downtime',
        'notify_on_recovery',
        'notify_on_malware_detected',
        'notify_on_ssl_expiry',
        'ssl_expiry_warning_days',
        'last_ssl_expiry_notified_at',
        'last_downtime_notified_at',
        'security_scan_status',
        'security_threats_count',
        'security_scan_report',
        'last_security_scan_at',
    ];

    protected function casts(): array
    {
        return [
            'known_files_size_bytes' => 'integer',
            'known_db_size_bytes' => 'integer',
            'uptime_monitoring_enabled' => 'boolean',
            'last_http_status_code' => 'integer',
            'last_response_time_ms' => 'integer',
            'ssl_expires_at' => 'datetime',
            'ssl_days_remaining' => 'integer',
            'last_uptime_checked_at' => 'datetime',
            'notify_on_downtime' => 'boolean',
            'notify_on_recovery' => 'boolean',
            'notify_on_malware_detected' => 'boolean',
            'notify_on_ssl_expiry' => 'boolean',
            'ssl_expiry_warning_days' => 'integer',
            'last_ssl_expiry_notified_at' => 'datetime',
            'last_downtime_notified_at' => 'datetime',
            'security_threats_count' => 'integer',
            'security_scan_report' => 'array',
            'last_security_scan_at' => 'datetime',
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

        static::created(function (self $website): void {
            app(VerifiedDomainSync::class)->linkWebsite($website);
            app(WebsiteMonitorSync::class)->ensureMonitorFor($website);
        });

        static::updated(function (self $website): void {
            if ($website->wasChanged('domain')) {
                app(VerifiedDomainSync::class)->linkWebsite($website);
                $website->unsetRelation('verifiedDomain');
            }

            app(WebsiteMonitorSync::class)->syncFromWebsite($website);
        });
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    public static function extractDomain(string $url): string
    {
        if (! str_contains($url, '://')) {
            $url = 'https://'.$url;
        }
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        return Str::lower(trim($host, '/'));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The ownership record of this website's domain — the single source of its verification status.
     */
    public function verifiedDomain(): HasOne
    {
        return $this->hasOne(VerifiedDomain::class);
    }

    /**
     * Websites whose domain ownership is proven.
     */
    public function scopeOwnershipVerified(Builder $query): void
    {
        $query->whereHas('verifiedDomain', fn (Builder $verifiedDomain) => $verifiedDomain->whereNotNull('verified_at'));
    }

    public function verificationStatus(): WebsiteVerificationStatus
    {
        return $this->verifiedDomain?->status() ?? WebsiteVerificationStatus::Unverified;
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
        return $this->verifiedDomain?->isVerified() ?? false;
    }
}
