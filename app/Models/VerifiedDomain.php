<?php

namespace App\Models;

use App\Enums\WebsiteVerificationStatus;
use App\Services\Monitoring\WebsiteMonitorSync;
use App\Services\Verification\OwnershipChallengeChecker;
use Database\Factories\VerifiedDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The single record of domain ownership for a workspace: the challenge token, how it was proven and when.
 * A backup website links to the row of its domain (website_id) and reads its verification status from it;
 * port checks and the "verified owner" badges read the same rows.
 */
class VerifiedDomain extends Model
{
    /** @use HasFactory<VerifiedDomainFactory> */
    use HasFactory;

    public const DNS_PREFIX = OwnershipChallengeChecker::DNS_PREFIX;

    public const FILE_PATH = OwnershipChallengeChecker::FILE_PATH;

    public const META_TAG_NAME = OwnershipChallengeChecker::META_TAG_NAME;

    /** Verified domains are re-checked this often (days). */
    public const REVERIFY_AFTER_DAYS = 7;

    /** Consecutive failed re-checks that revoke the verification. */
    public const REVOKE_AFTER_FAILURES = 2;

    protected $fillable = [
        'workspace_id',
        'website_id',
        'domain',
        'token',
        'verification_method',
        'verified_at',
        'last_attempted_at',
        'last_error',
        'reverify_failures',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'reverify_failures' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $verifiedDomain): void {
            $verifiedDomain->domain = self::normalize($verifiedDomain->domain);

            if (empty($verifiedDomain->token)) {
                $verifiedDomain->token = 'sv-verify-'.Str::lower(Str::random(32));
            }
        });

        // A backup website whose domain becomes proven gets its uptime monitor right away.
        static::saved(function (self $verifiedDomain): void {
            if ($verifiedDomain->isVerified()
                && $verifiedDomain->website_id !== null
                && $verifiedDomain->wasChanged(['verified_at', 'website_id'])
                && ($website = $verifiedDomain->website()->first()) !== null) {
                app(WebsiteMonitorSync::class)->ensureMonitorFor($website);
            }
        });
    }

    public static function normalize(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));
        $domain = parse_url(str_contains($domain, '://') ? $domain : "https://{$domain}", PHP_URL_HOST) ?: $domain;

        return (string) preg_replace('/^www\./', '', rtrim($domain, '.'));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * The domain of a backup website: shown with its website, and its proof gates backups.
     */
    public function isFromBackupWebsite(): bool
    {
        return $this->website_id !== null;
    }

    /**
     * The website-facing status: proven, failed on the last attempt, or waiting for the first check.
     */
    public function status(): WebsiteVerificationStatus
    {
        return match (true) {
            $this->isVerified() => WebsiteVerificationStatus::Verified,
            filled($this->last_error) => WebsiteVerificationStatus::Failed,
            default => WebsiteVerificationStatus::Pending,
        };
    }

    public function methodLabel(): string
    {
        return match ($this->verification_method) {
            'html_file' => 'verification file',
            'meta_tag' => 'HTML meta tag',
            'dns_txt' => 'DNS TXT record',
            default => 'unknown method',
        };
    }

    public function dnsRecordName(): string
    {
        return self::DNS_PREFIX.$this->domain;
    }

    /**
     * The last failed attempt, one line per verification method.
     *
     * @return list<string>
     */
    public function errorLines(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $this->last_error))));
    }

    /**
     * The failure lines as an HTML list (✗ per method), for tables, modals and notifications.
     */
    public function errorListHtml(): HtmlString
    {
        return new HtmlString(collect($this->errorLines())
            ->map(fn (string $line): string => '<div>✗ '.e($line).'</div>')
            ->implode(''));
    }

    public function metaTag(): string
    {
        return '<meta name="'.self::META_TAG_NAME.'" content="'.$this->token.'">';
    }
}
