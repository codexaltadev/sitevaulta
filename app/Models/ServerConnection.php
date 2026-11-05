<?php

namespace App\Models;

use App\Enums\DatabaseDriver;
use App\Enums\FileProtocol;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerConnection extends Model
{
    protected $fillable = [
        'workspace_id',
        'website_id',
        'name',
        'file_protocol',
        'file_credentials',
        'db_driver',
        'db_use_ssh_tunnel',
        'db_credentials',
        'capabilities',
        'health_status',
        'last_tested_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'file_protocol' => FileProtocol::class,
            'file_credentials' => 'encrypted:array',
            'db_driver' => DatabaseDriver::class,
            'db_use_ssh_tunnel' => 'boolean',
            'db_credentials' => 'encrypted:array',
            'capabilities' => 'array',
            'last_tested_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function backupPolicies(): HasMany
    {
        return $this->hasMany(BackupPolicy::class);
    }

    public function supportsIncremental(): bool
    {
        return (bool) data_get($this->capabilities, 'inc_files', true);
    }
}

