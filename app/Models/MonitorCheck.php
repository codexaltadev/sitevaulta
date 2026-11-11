<?php

namespace App\Models;

use App\Enums\MonitorStatus;
use Database\Factories\MonitorCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorCheck extends Model
{
    /** @use HasFactory<MonitorCheckFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'monitor_id',
        'status',
        'http_status_code',
        'response_time_ms',
        'region',
        'error_message',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MonitorStatus::class,
            'http_status_code' => 'integer',
            'response_time_ms' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
