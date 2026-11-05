<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class SystemSetting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'is_encrypted',
        'is_public',
        'label',
        'helper_text',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getTypedValue(): mixed
    {
        $raw = $this->value;

        if ($raw === null) {
            return null;
        }

        if ($this->is_encrypted && $raw !== '') {
            try {
                $raw = Crypt::decryptString($raw);
            } catch (\Throwable) {
                // Fallback if stored unencrypted during seeding
            }
        }

        return match ($this->type) {
            'integer' => (int) $raw,
            'float' => (float) $raw,
            'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'json' => is_array($raw) ? $raw : (json_decode((string) $raw, true) ?? []),
            default => (string) $raw,
        };
    }

    public function setTypedValue(mixed $val): void
    {
        $serialized = match ($this->type) {
            'boolean' => $val ? '1' : '0',
            'json' => is_string($val) ? $val : json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => $val !== null ? (string) $val : null,
        };

        if ($this->is_encrypted && $serialized !== null && $serialized !== '') {
            $serialized = Crypt::encryptString($serialized);
        }

        $this->value = $serialized;
    }
}

