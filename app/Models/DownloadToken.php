<?php

namespace App\Models;

use App\Enums\DownloadMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DownloadToken extends Model
{
    protected $fillable = [
        'token_hash',
        'workspace_id',
        'user_id',
        'backup_artifact_id',
        'mode',
        'status',
        'retry_count',
        'ip_address',
        'claimed_at',
        'consumed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => DownloadMode::class,
            'retry_count' => 'integer',
            'claimed_at' => 'datetime',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function backupArtifact(): BelongsTo
    {
        return $this->belongsTo(BackupArtifact::class);
    }
}

