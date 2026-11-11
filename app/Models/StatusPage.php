<?php

namespace App\Models;

use App\Services\Monitoring\NetworkProbe;
use Database\Factories\StatusPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * A public (optionally password-protected) page at /status/{slug} showing the health of selected monitors.
 */
class StatusPage extends Model
{
    /** @use HasFactory<StatusPageFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'description',
        'password',
        'is_published',
        'show_uptime_bars',
        'show_incident_history',
        'custom_domain',
        'allow_subscriptions',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_published' => 'boolean',
            'show_uptime_bars' => 'boolean',
            'show_incident_history' => 'boolean',
            'allow_subscriptions' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $statusPage): void {
            $statusPage->custom_domain = self::normalizeDomain($statusPage->custom_domain);
        });

        // The custom-domain middleware caches host → page; a changed domain or publish state applies at once.
        $forgetDomains = function (self $statusPage): void {
            foreach (array_filter([$statusPage->custom_domain, $statusPage->getOriginal('custom_domain')]) as $domain) {
                Cache::forget("status-page-domain:{$domain}");
            }
        };
        static::saved($forgetDomains);
        static::deleted($forgetDomains);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class);
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(StatusPageAnnouncement::class)->latest();
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(StatusPageSubscriber::class);
    }

    public function isPasswordProtected(): bool
    {
        return filled($this->password);
    }

    /**
     * The address visitors use: the custom domain when one is set, otherwise /status/{slug}.
     */
    public function publicUrl(): string
    {
        return filled($this->custom_domain)
            ? "https://{$this->custom_domain}"
            : route('status-pages.show', ['slug' => $this->slug]);
    }

    public static function normalizeDomain(?string $domain): ?string
    {
        $domain = mb_strtolower(trim((string) $domain));
        $domain = parse_url(str_contains($domain, '://') ? $domain : "https://{$domain}", PHP_URL_HOST) ?: $domain;
        $domain = rtrim(trim($domain, '/'), '.');

        return $domain === '' ? null : $domain;
    }

    /**
     * Whether the custom domain already points at this application (same IP addresses as our own host).
     * Null when no custom domain is set or our own host cannot be resolved.
     */
    public function customDomainPointsHere(): ?bool
    {
        $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if (blank($this->custom_domain) || $appHost === '') {
            return null;
        }

        $probe = app(NetworkProbe::class);
        $ourIps = $probe->resolveIps($appHost);

        return $ourIps === [] ? null : array_intersect($ourIps, $probe->resolveIps($this->custom_domain)) !== [];
    }

    /**
     * Session key that marks this page as unlocked for the current visitor.
     */
    public function unlockSessionKey(): string
    {
        return "status_page_unlocked.{$this->id}";
    }
}
