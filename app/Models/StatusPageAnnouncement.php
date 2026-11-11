<?php

namespace App\Models;

use Database\Factories\StatusPageAnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message on a public status page: planned maintenance, an incident update, or general information.
 * Shown at the top while open; listed in the history once resolved.
 */
class StatusPageAnnouncement extends Model
{
    /** @use HasFactory<StatusPageAnnouncementFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'info' => 'Information',
        'maintenance' => 'Maintenance',
        'incident' => 'Incident update',
    ];

    protected $fillable = [
        'status_page_id',
        'type',
        'title',
        'body',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }
}
