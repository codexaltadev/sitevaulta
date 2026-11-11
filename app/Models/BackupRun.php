<?php

namespace App\Models;

use App\Enums\BackupRunStatus;
use App\Enums\BackupScope;
use Database\Factories\BackupRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BackupRun extends Model
{
    /** @use HasFactory<BackupRunFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'workspace_id',
        'website_id',
        'backup_policy_id',
        'backup_chain_id',
        'parent_run_id',
        'trigger_type',
        'trigger_tag',
        'email_provider_mode',
        'notification_email',
        'notify_on_success',
        'notify_on_failure',
        'backup_type',
        'scope',
        'status',
        'source_files_count',
        'changed_files_count',
        'raw_source_size_bytes',
        'archive_size_bytes',
        'is_anomaly',
        'anomaly_reason',
        'attempt_count',
        'started_at',
        'completed_at',
        'duration_seconds',
        'retention_expires_at',
        'is_pruned',
        'error_code',
        'error_message',
        'notification_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => BackupScope::class,
            'status' => BackupRunStatus::class,
            'notify_on_success' => 'boolean',
            'notify_on_failure' => 'boolean',
            'source_files_count' => 'integer',
            'changed_files_count' => 'integer',
            'raw_source_size_bytes' => 'integer',
            'archive_size_bytes' => 'integer',
            'is_anomaly' => 'boolean',
            'attempt_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'duration_seconds' => 'integer',
            'retention_expires_at' => 'datetime',
            'is_pruned' => 'boolean',
            'notification_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            if (empty($run->uuid)) {
                $run->uuid = (string) Str::uuid();
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

    public function backupPolicy(): BelongsTo
    {
        return $this->belongsTo(BackupPolicy::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(BackupPolicy::class, 'backup_policy_id');
    }

    public function backupChain(): BelongsTo
    {
        return $this->belongsTo(BackupChain::class);
    }

    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(BackupArtifact::class);
    }

    public function formattedSize(): string
    {
        $bytes = (int) ($this->archive_size_bytes ?? 0);
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $pow), 2).' '.$units[$pow];
    }

    public function progressPercent(): int
    {
        return $this->status?->progressPercent() ?? 0;
    }

    public function stageDescription(): string
    {
        return $this->status?->stageDescription() ?? 'Processing...';
    }
}
