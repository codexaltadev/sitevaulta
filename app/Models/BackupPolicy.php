<?php

namespace App\Models;

use App\Enums\BackupScope;
use App\Enums\BackupStrategy;
use App\Enums\DownloadMode;
use App\Enums\ScheduleFrequency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BackupPolicy extends Model
{
    protected $fillable = [
        'uuid',
        'workspace_id',
        'website_id',
        'server_connection_id',
        'storage_connection_id',
        'template_id',
        'name',
        'scope',
        'strategy',
        'full_reset_every_n',
        'schedule_frequency',
        'interval_minutes',
        'cron_expression',
        'retention_days',
        'exclude_paths',
        'enable_encryption',
        'encryption_passphrase',
        'download_mode',
        'forecast_snapshot',
        'status',
        'last_run_at',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => BackupScope::class,
            'strategy' => BackupStrategy::class,
            'full_reset_every_n' => 'integer',
            'schedule_frequency' => ScheduleFrequency::class,
            'interval_minutes' => 'integer',
            'retention_days' => 'integer',
            'exclude_paths' => 'array',
            'enable_encryption' => 'boolean',
            'encryption_passphrase' => 'encrypted',
            'download_mode' => DownloadMode::class,
            'forecast_snapshot' => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $policy): void {
            if (empty($policy->uuid)) {
                $policy->uuid = (string) Str::uuid();
            }
            if (empty($policy->next_run_at)) {
                $policy->next_run_at = now()->utc()->addMinutes($policy->interval_minutes ?: 1440);
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function serverConnection(): BelongsTo
    {
        return $this->belongsTo(ServerConnection::class);
    }

    public function storageConnection(): BelongsTo
    {
        return $this->belongsTo(StorageConnection::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(BackupTemplate::class, 'template_id');
    }

    public function backupChains(): HasMany
    {
        return $this->hasMany(BackupChain::class);
    }

    public function backupRuns(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }
}

