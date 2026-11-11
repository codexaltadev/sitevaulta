<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an incident's activity log (detected, confirmed, alert_sent, resolved).
 */
class MonitorIncidentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'monitor_incident_id',
        'type',
        'message',
        'probe_location',
        'delivery_status',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(MonitorIncident::class, 'monitor_incident_id');
    }
}
