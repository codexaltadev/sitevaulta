<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteVerification extends Model
{
    protected $fillable = [
        'workspace_id',
        'website_id',
        'token',
        'dns_host',
        'file_path',
        'meta_tag_name',
        'last_checked_at',
        'last_error',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'expires_at' => 'datetime',
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

