<?php

namespace App\Models;

use App\Enums\BackupScope;
use App\Enums\BackupStrategy;
use App\Enums\ScheduleFrequency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupTemplate extends Model
{
    protected $fillable = [
        'workspace_id',
        'is_global_default',
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
        'notification_events',
    ];

    protected function casts(): array
    {
        return [
            'is_global_default' => 'boolean',
            'scope' => BackupScope::class,
            'strategy' => BackupStrategy::class,
            'full_reset_every_n' => 'integer',
            'schedule_frequency' => ScheduleFrequency::class,
            'interval_minutes' => 'integer',
            'retention_days' => 'integer',
            'exclude_paths' => 'array',
            'enable_encryption' => 'boolean',
            'notification_events' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function backupPolicies(): HasMany
    {
        return $this->hasMany(BackupPolicy::class, 'template_id');
    }
}

