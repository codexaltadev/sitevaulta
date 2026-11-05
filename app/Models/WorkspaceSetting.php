<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSetting extends Model
{
    protected $fillable = [
        'workspace_id',
        'group',
        'key',
        'value',
        'type',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function getTypedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'integer' => (int) $this->value,
            'float' => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->value, true) ?? [],
            default => (string) $this->value,
        };
    }
}

