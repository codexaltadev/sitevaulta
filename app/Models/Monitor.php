<?php

namespace App\Models;

use App\Enums\MonitorIncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use Carbon\CarbonInterface;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'workspace_id',
        'website_id',
        'parent_id',
        'expires_at',
        'manual_expires_at',
        'name',
        'type',
        'url',
        'host',
        'port',
        'ports',
        'http_method',
        'keyword',
        'keyword_should_exist',
        'keyword_case_sensitive',
        'follow_redirects',
        'heartbeat_grace_seconds',
        'last_heartbeat_at',
        'dns_record_type',
        'dns_record_types',
        'dns_expected_value',
        'dns_baseline',
        'expiry_threshold_days',
        'ping_strict_icmp',
        'last_probe_method',
        'alert_delay_minutes',
        'alert_repeat_minutes',
        'alert_channel_ids',
        'notify_workspace_members',
        'interval_seconds',
        'timeout_seconds',
        'status',
        'consecutive_failures',
        'last_response_time_ms',
        'last_http_status_code',
        'last_error',
        'last_checked_at',
        'last_status_changed_at',
    ];

    protected $attributes = [
        'type' => 'http',
        'status' => 'pending',
        'http_method' => 'GET',
        'interval_seconds' => 300,
        'timeout_seconds' => 30,
        'keyword_should_exist' => true,
        'keyword_case_sensitive' => false,
        'follow_redirects' => true,
        'consecutive_failures' => 0,
        'heartbeat_grace_seconds' => 300,
        'expiry_threshold_days' => 14,
        'alert_delay_minutes' => 0,
        'alert_repeat_minutes' => 0,
        'notify_workspace_members' => true,
    ];

    protected $hidden = [
        'heartbeat_token',
    ];

    protected function casts(): array
    {
        return [
            'type' => MonitorType::class,
            'status' => MonitorStatus::class,
            'port' => 'integer',
            'ports' => 'array',
            'dns_record_types' => 'array',
            'dns_baseline' => 'array',
            'keyword_should_exist' => 'boolean',
            'keyword_case_sensitive' => 'boolean',
            'follow_redirects' => 'boolean',
            'interval_seconds' => 'integer',
            'timeout_seconds' => 'integer',
            'consecutive_failures' => 'integer',
            'last_response_time_ms' => 'integer',
            'last_http_status_code' => 'integer',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'first_checked_at' => 'datetime',
            'last_status_changed_at' => 'datetime',
            'expires_at' => 'datetime',
            'manual_expires_at' => 'date',
            'heartbeat_grace_seconds' => 'integer',
            'last_heartbeat_at' => 'datetime',
            'expiry_threshold_days' => 'integer',
            'ping_strict_icmp' => 'boolean',
            'alert_delay_minutes' => 'integer',
            'alert_repeat_minutes' => 'integer',
            'alert_channel_ids' => 'array',
            'notify_workspace_members' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $monitor): void {
            if (empty($monitor->uuid)) {
                $monitor->uuid = (string) Str::uuid();
            }
        });

        static::saving(function (self $monitor): void {
            if ($monitor->type === MonitorType::Heartbeat && empty($monitor->heartbeat_token)) {
                $monitor->heartbeat_token = Str::random(40);
            }

            // One main monitor per host per workspace (enforced by a unique index on workspace_id + target_host).
            $monitor->target_host = $monitor->computeTargetHost();

            // Port monitors store their port list in `ports`; keep `port` as the first one for display.
            if ($monitor->type === MonitorType::Port) {
                $ports = self::normalizePorts($monitor->ports ?? ($monitor->port ? [$monitor->port] : []));
                $monitor->ports = $ports === [] ? null : $ports;
                $monitor->port = $ports[0] ?? $monitor->port;
            }

            // A daily expiry check should pick up a newly entered date on the next scheduler tick.
            if ($monitor->exists && $monitor->isDirty('manual_expires_at')) {
                $monitor->last_checked_at = null;
            }

            // New DNS settings start without a baseline: until the record is seen once, a miss means
            // "misconfigured", not "the record disappeared".
            if ($monitor->exists && $monitor->type === MonitorType::Dns && $monitor->isDirty(['host', 'dns_record_type', 'dns_record_types', 'dns_expected_value'])) {
                $monitor->last_probe_method = null;
                $monitor->last_checked_at = null;
                $monitor->dns_baseline = null;
            }

            // The scheduler only looks at monitors whose next_check_at has passed (null = due now).
            if ($monitor->isDirty(['last_checked_at', 'interval_seconds', 'type'])) {
                $monitor->next_check_at = $monitor->last_checked_at?->copy()->addSeconds(
                    $monitor->type === MonitorType::Heartbeat ? 60 : max(30, (int) $monitor->interval_seconds),
                );
            }
        });
    }

    /**
     * Well-known service ports offered as quick picks for Port monitors.
     *
     * @var array<int, string>
     */
    public const COMMON_PORTS = [
        21 => 'FTP',
        22 => 'SSH',
        25 => 'SMTP',
        80 => 'HTTP',
        443 => 'HTTPS',
        465 => 'SMTPS',
        587 => 'SMTP submission',
        993 => 'IMAPS',
        2083 => 'cPanel',
        2087 => 'WHM',
        3306 => 'MySQL',
        5432 => 'PostgreSQL',
        6379 => 'Redis',
        27017 => 'MongoDB',
    ];

    public const MAX_PORTS = 10;

    /**
     * @param  iterable<mixed>  $ports
     * @return list<int>
     */
    public static function normalizePorts(iterable $ports): array
    {
        $normalized = collect($ports)
            ->map(fn (mixed $port): int => (int) $port)
            ->filter(fn (int $port): bool => $port >= 1 && $port <= 65535)
            ->unique()
            ->values()
            ->all();

        return array_slice($normalized, 0, self::MAX_PORTS);
    }

    /**
     * @return list<int>
     */
    public function portList(): array
    {
        return self::normalizePorts($this->ports ?? ($this->port ? [$this->port] : []));
    }

    /**
     * DNS record types this monitor watches (falls back to the single legacy type).
     *
     * @return list<string>
     */
    public function dnsRecordTypes(): array
    {
        $types = array_values(array_unique(array_filter((array) ($this->dns_record_types ?: [$this->dns_record_type ?: 'A']))));

        return $types === [] ? ['A'] : $types;
    }

    /**
     * The host a URL or hostname points at, in the form used to detect duplicates: lower-case,
     * no scheme, path, port or trailing dot, and "www." removed (www.example.com = example.com).
     * Sub-domains stay distinct (shop.example.com ≠ example.com).
     */
    public static function normalizeHost(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        $host = str_contains($value, '://') ? parse_url($value, PHP_URL_HOST) : (parse_url("http://{$value}", PHP_URL_HOST) ?: $value);
        $host = (string) preg_replace('/^www\./', '', rtrim(trim((string) $host, '[]'), '.'));

        return $host === '' ? null : $host;
    }

    /**
     * The host this monitor watches, for main monitors only (extra checks share their parent's host).
     */
    public function computeTargetHost(): ?string
    {
        if ($this->parent_id !== null || $this->type === MonitorType::Heartbeat) {
            return null;
        }

        return self::normalizeHost($this->type?->usesUrl() ? $this->url : $this->host);
    }

    /**
     * The host whose ownership matters for this monitor; extra checks use their own host. Null for heartbeats.
     */
    public function ownershipHost(): ?string
    {
        if ($this->type === MonitorType::Heartbeat) {
            return null;
        }

        return $this->target_host ?? self::normalizeHost($this->type?->usesUrl() ? $this->url : $this->host);
    }

    public static function portLabel(int $port): string
    {
        return isset(self::COMMON_PORTS[$port]) ? self::COMMON_PORTS[$port]." {$port}" : (string) $port;
    }

    public function heartbeatUrl(): ?string
    {
        return $this->type === MonitorType::Heartbeat && $this->heartbeat_token
            ? route('heartbeats.ping', ['token' => $this->heartbeat_token])
            : null;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * The main monitor this extra check (Ping / SSL / Domain / DNS) belongs to.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Extra checks that run against the same target and are reported together with this monitor.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Short metric shown on the child-check pill: "32 ms", "45d left", "MX ok".
     */
    public function headlineMetric(): string
    {
        if ($this->status === MonitorStatus::Down) {
            return 'Down';
        }

        if ($this->isInconclusive()) {
            return 'Unknown';
        }

        if ($this->type->isExpiryCheck() && $this->expires_at !== null) {
            return max(0, (int) floor(now()->diffInDays($this->expires_at, false))).'d left';
        }

        return match (true) {
            $this->status !== MonitorStatus::Up => $this->status->label(),
            $this->type === MonitorType::Port => count($this->portList()).'/'.count($this->portList()).' open',
            $this->last_response_time_ms !== null => "{$this->last_response_time_ms} ms".($this->measuredOverTcp() ? ' (TCP)' : ''),
            default => 'OK',
        };
    }

    /**
     * True when a ping monitor's last check fell back to a TCP connect because ICMP got no reply.
     */
    public function measuredOverTcp(): bool
    {
        return str_starts_with((string) $this->last_probe_method, 'tcp:');
    }

    /**
     * A domain check whose registry publishes no expiry date and no manual date was entered.
     */
    public function expiryIsUnknown(): bool
    {
        return $this->last_probe_method === 'unavailable';
    }

    /**
     * The last check could not give a trustworthy answer (missing expiry data, our DNS resolver failing,
     * or a DNS record that never existed with these settings). Shown as "Unknown", never as an outage.
     */
    public function isInconclusive(): bool
    {
        return in_array($this->last_probe_method, ['unavailable', 'dns:inconclusive', 'dns:misconfigured'], true);
    }

    public function probeMethodLabel(): ?string
    {
        return match (true) {
            $this->last_probe_method === 'dns:ok' => 'DNS lookup compared with the saved baseline',
            $this->last_probe_method === 'dns:inconclusive' => 'Unknown — our DNS resolver did not answer; checking again soon',
            $this->last_probe_method === 'dns:misconfigured' => 'Some selected record types have never existed for this host — untick them (a root domain cannot have a CNAME)',
            $this->last_probe_method === 'rdap' => 'RDAP registry lookup',
            $this->last_probe_method === 'manual' => 'Manual expiry date (this registry publishes no RDAP/WHOIS data)',
            $this->last_probe_method === 'cached' => 'Last known expiry date (the RDAP lookup failed this time)',
            $this->expiryIsUnknown() => 'Not available — this registry (e.g. .bd) publishes no expiry date. Add the date manually in Edit.',
            $this->last_probe_method === 'icmp' => 'ICMP ping',
            $this->measuredOverTcp() => 'TCP port '.substr((string) $this->last_probe_method, 4).' — the server does not answer ping (firewall)',
            default => null,
        };
    }

    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(MonitorIncident::class);
    }

    public function maintenanceWindows(): BelongsToMany
    {
        return $this->belongsToMany(MaintenanceWindow::class);
    }

    public function statusPages(): BelongsToMany
    {
        return $this->belongsToMany(StatusPage::class);
    }

    public function isUnderMaintenance(?CarbonInterface $moment = null): bool
    {
        $moment ??= now()->utc();

        return $this->maintenanceWindows->contains(function (MaintenanceWindow $window) use ($moment): bool {
            $window->setRelation('workspace', $this->workspace);

            return $window->isInProgressAt($moment);
        });
    }

    public function nextMaintenanceStart(?CarbonInterface $moment = null): ?CarbonInterface
    {
        $moment ??= now()->utc();

        return $this->maintenanceWindows
            ->map(function (MaintenanceWindow $window) use ($moment): ?CarbonInterface {
                $window->setRelation('workspace', $this->workspace);

                return $window->nextStartAfter($moment);
            })
            ->filter()
            ->sort()
            ->first();
    }

    public function ongoingIncident(): HasOne
    {
        return $this->hasOne(MonitorIncident::class)
            ->where('status', MonitorIncidentStatus::Ongoing)
            ->latestOfMany('started_at');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', '!=', MonitorStatus::Paused);
    }

    public function isDueForCheck(): bool
    {
        if ($this->status === MonitorStatus::Paused) {
            return false;
        }

        if ($this->type === MonitorType::Heartbeat) {
            // Stay "pending" until the first ping is overdue; afterwards evaluate the deadline every minute.
            if ($this->last_heartbeat_at === null
                && $this->created_at?->copy()->addSeconds($this->interval_seconds + $this->heartbeat_grace_seconds)->gt(now()->utc())) {
                return false;
            }

            return $this->last_checked_at === null || $this->last_checked_at->addMinute()->lte(now()->utc());
        }

        return $this->last_checked_at === null
            || $this->last_checked_at->addSeconds($this->interval_seconds)->lte(now()->utc());
    }

    /**
     * Human-readable target shown under the monitor name, e.g. "https://example.com" or "db.example.com:3306".
     */
    public function targetLabel(): string
    {
        return match ($this->type) {
            MonitorType::Port => "{$this->host} : ".implode(', ', $this->portList()),
            MonitorType::Ssl => "{$this->host}:".($this->port ?: 443),
            MonitorType::Dns => implode('/', $this->dnsRecordTypes())." {$this->host}",
            MonitorType::Ping, MonitorType::Domain => (string) $this->host,
            MonitorType::Heartbeat => 'Incoming heartbeat',
            default => (string) $this->url,
        };
    }

    public function intervalLabel(): string
    {
        $minutes = intdiv($this->interval_seconds, 60);

        return $minutes >= 60 && $minutes % 60 === 0
            ? intdiv($minutes, 60).' hr'
            : ($minutes >= 1 ? "{$minutes} min" : "{$this->interval_seconds} sec");
    }
}
