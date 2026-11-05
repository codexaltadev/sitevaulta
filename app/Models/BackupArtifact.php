<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BackupArtifact extends Model
{
    protected $fillable = [
        'uuid',
        'workspace_id',
        'backup_run_id',
        'storage_connection_id',
        'artifact_type',
        'remote_path',
        'file_name',
        'size_bytes',
        'sha256_checksum',
        'compression_algo',
        'is_encrypted',
        'encryption_iv',
        'file_index_manifest',
        'verification_status',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_encrypted' => 'boolean',
            'file_index_manifest' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $artifact): void {
            if (empty($artifact->uuid)) {
                $artifact->uuid = (string) Str::uuid();
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function backupRun(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class);
    }

    public function storageConnection(): BelongsTo
    {
        return $this->belongsTo(StorageConnection::class);
    }

    public function downloadTokens(): HasMany
    {
        return $this->hasMany(DownloadToken::class);
    }
}

