<?php

namespace App\Models;

use App\Enums\NotificationChannelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationChannel extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'type',
        'config',
        'events',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationChannelType::class,
            'config' => 'encrypted:array',
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}

