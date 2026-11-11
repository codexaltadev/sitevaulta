<?php

namespace App\Models;

use App\Enums\StorageDriver;
use Database\Factories\StorageConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageConnection extends Model
{
    /** @use HasFactory<StorageConnectionFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'driver',
        'credentials',
        'root_prefix',
        'supports_presigned_url',
        'quota_total_bytes',
        'quota_used_bytes',
        'health_status',
        'last_verified_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'driver' => StorageDriver::class,
            'credentials' => 'encrypted:array',
            'supports_presigned_url' => 'boolean',
            'quota_total_bytes' => 'integer',
            'quota_used_bytes' => 'integer',
            'last_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $storage): void {
            if ($storage->driver instanceof StorageDriver) {
                $storage->supports_presigned_url = $storage->driver->supportsPresignedUrls();
            }

            $creds = is_array($storage->credentials) ? $storage->credentials : [];

            if ($storage->driver === StorageDriver::Sftp && ! empty($creds['root_directory'])) {
                $storage->root_prefix = trim((string) $creds['root_directory']);
            } elseif ($storage->driver === StorageDriver::GoogleDrive && ! empty($creds['folder_id'])) {
                $storage->root_prefix = trim((string) $creds['folder_id']);
            }

            if (empty($storage->root_prefix)) {
                $storage->root_prefix = 'sitevaulta-backups';
            }

            if ($storage->quota_total_bytes === null) {
                $storage->quota_total_bytes = 16106127360; // Default 15 GB
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function backupPolicies(): HasMany
    {
        return $this->hasMany(BackupPolicy::class);
    }

    public function backupArtifacts(): HasMany
    {
        return $this->hasMany(BackupArtifact::class);
    }
}
