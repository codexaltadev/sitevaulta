<?php

namespace App\Models;

use App\Services\Verification\OwnershipChallengeChecker;
use Database\Factories\VerifiedDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A domain the workspace proved it controls (DNS TXT or a file on the site) so it may run invasive
 * checks such as port monitoring against it — without creating a backup website.
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

    /** Proven by verifying the backup website in Backup Management (kept in sync automatically). */
    public const METHOD_BACKUP_WEBSITE = 'backup_website';

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

    public function isFromBackupWebsite(): bool
    {
        return $this->verification_method === self::METHOD_BACKUP_WEBSITE;
    }

    public function methodLabel(): string
    {
        return match ($this->verification_method) {
            self::METHOD_BACKUP_WEBSITE => 'backup website verification',
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

    public function metaTag(): string
    {
        return '<meta name="'.self::META_TAG_NAME.'" content="'.$this->token.'">';
    }
}
