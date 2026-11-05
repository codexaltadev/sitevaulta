<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientReport extends Model
{
    protected $fillable = [
        'workspace_id',
        'website_id',
        'recipient_email',
        'frequency',
        'last_sent_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
            'is_active' => 'boolean',
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
}

