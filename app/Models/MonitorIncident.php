<?php

namespace App\Models;

use App\Enums\MonitorIncidentStatus;
use Database\Factories\MonitorIncidentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MonitorIncident extends Model
{
    /** @use HasFactory<MonitorIncidentFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'workspace_id',
        'monitor_id',
        'status',
        'root_cause_type',
        'root_cause',
        'region',
        'request_snapshot',
        'response_headers',
        'response_body',
        'is_excluded_from_reports',
        'started_at',
        'resolved_at',
        'duration_seconds',
        'alerted_at',
        'last_reminder_at',
        'reminder_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => MonitorIncidentStatus::class,
            'request_snapshot' => 'array',
            'response_headers' => 'array',
            'is_excluded_from_reports' => 'boolean',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'duration_seconds' => 'integer',
            'alerted_at' => 'datetime',
            'last_reminder_at' => 'datetime',
            'reminder_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $incident): void {
            if (empty($incident->uuid)) {
                $incident->uuid = (string) Str::uuid();
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MonitorIncidentEvent::class);
    }
}
