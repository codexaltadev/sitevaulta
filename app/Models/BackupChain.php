<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BackupChain extends Model
{
    protected $fillable = [
        'uuid',
        'workspace_id',
        'website_id',
        'backup_policy_id',
        'base_full_run_id',
        'incremental_count',
        'total_chain_size_bytes',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'incremental_count' => 'integer',
            'total_chain_size_bytes' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $chain): void {
            if (empty($chain->uuid)) {
                $chain->uuid = (string) Str::uuid();
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

    public function baseFullRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'base_full_run_id');
    }

    public function backupRuns(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }
}

