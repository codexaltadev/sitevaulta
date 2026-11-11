<?php

namespace App\Models;

use Database\Factories\StatusPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A public (optionally password-protected) page at /status/{slug} showing the health of selected monitors.
 */
class StatusPage extends Model
{
    /** @use HasFactory<StatusPageFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'description',
        'password',
        'is_published',
        'show_uptime_bars',
        'show_incident_history',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_published' => 'boolean',
            'show_uptime_bars' => 'boolean',
            'show_incident_history' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class);
    }

    public function isPasswordProtected(): bool
    {
        return filled($this->password);
    }

    public function publicUrl(): string
    {
        return route('status-pages.show', ['slug' => $this->slug]);
    }

    /**
     * Session key that marks this page as unlocked for the current visitor.
     */
    public function unlockSessionKey(): string
    {
        return "status_page_unlocked.{$this->id}";
    }
}
