<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Workspace extends Model implements HasName
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'owner_id',
        'billing_currency',
        'timezone',
        'email_credits_balance',
        'email_provider_mode',
        'custom_smtp_config',
        'referral_code',
        'referred_by_workspace_id',
        'referral_rewarded_at',
        'whitelabel_config',
        'is_suspended',
    ];

    protected function casts(): array
    {
        return [
            'email_credits_balance' => 'integer',
            'custom_smtp_config' => 'encrypted:array',
            'referral_rewarded_at' => 'datetime',
            'whitelabel_config' => 'array',
            'is_suspended' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $workspace): void {
            if (empty($workspace->uuid)) {
                $workspace->uuid = (string) Str::uuid();
            }
            if (empty($workspace->slug)) {
                $baseSlug = Str::slug($workspace->name ?: 'workspace');
                $workspace->slug = $baseSlug . '-' . Str::lower(Str::random(5));
            }
            if (empty($workspace->referral_code)) {
                $prefix = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $workspace->slug ?: 'SV'), 0, 6));
                $workspace->referral_code = 'SV-' . ($prefix ?: 'USER') . '-' . Str::upper(Str::random(4));
            }
        });
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->withPivot(['role', 'permissions'])
            ->withTimestamps();
    }

    public function settings(): HasMany
    {
        return $this->hasMany(WorkspaceSetting::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function websites(): HasMany
    {
        return $this->hasMany(Website::class);
    }

    public function websiteVerifications(): HasMany
    {
        return $this->hasMany(WebsiteVerification::class);
    }

    public function serverConnections(): HasMany
    {
        return $this->hasMany(ServerConnection::class);
    }

    public function storageConnections(): HasMany
    {
        return $this->hasMany(StorageConnection::class);
    }

    public function backupTemplates(): HasMany
    {
        return $this->hasMany(BackupTemplate::class);
    }

    public function backupPolicies(): HasMany
    {
        return $this->hasMany(BackupPolicy::class);
    }

    public function backupChains(): HasMany
    {
        return $this->hasMany(BackupChain::class);
    }

    public function backupRuns(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    public function backupArtifacts(): HasMany
    {
        return $this->hasMany(BackupArtifact::class);
    }

    public function downloadTokens(): HasMany
    {
        return $this->hasMany(DownloadToken::class);
    }

    public function notificationChannels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class);
    }

    public function clientReports(): HasMany
    {
        return $this->hasMany(ClientReport::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function emailWalletTransactions(): HasMany
    {
        return $this->hasMany(EmailWalletTransaction::class);
    }

    public function referralsMade(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_workspace_id');
    }

    public function referredByWorkspace(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_workspace_id');
    }

    public function hasValidCustomSmtp(): bool
    {
        $cfg = $this->custom_smtp_config ?? [];

        return ! empty($cfg['host']) && ! empty($cfg['from_address']);
    }

    public function hasActiveEntitlement(): bool
    {
        if ($this->is_suspended) {
            return false;
        }

        $sub = $this->currentSubscription;
        if (! $sub) {
            return false;
        }

        if ($sub->status === SubscriptionStatus::Trialing) {
            return $sub->trial_ends_at === null || $sub->trial_ends_at->isFuture();
        }

        if ($sub->status === SubscriptionStatus::Active) {
            return $sub->current_period_ends_at === null || $sub->current_period_ends_at->isFuture();
        }

        if ($sub->status === SubscriptionStatus::PastDue) {
            return $sub->grace_ends_at !== null && $sub->grace_ends_at->isFuture();
        }

        return false;
    }
}

