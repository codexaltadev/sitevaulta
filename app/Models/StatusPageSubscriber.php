<?php

namespace App\Models;

use Database\Factories\StatusPageSubscriberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A visitor who asked for status emails. Nothing is sent until the address is confirmed (double opt-in);
 * the same random token confirms and unsubscribes.
 */
class StatusPageSubscriber extends Model
{
    /** @use HasFactory<StatusPageSubscriberFactory> */
    use HasFactory;

    protected $fillable = [
        'status_page_id',
        'email',
        'token',
        'confirmed_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $subscriber): void {
            $subscriber->email = mb_strtolower(trim($subscriber->email));
            $subscriber->token ??= Str::random(48);
        });
    }

    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    public function scopeConfirmed(Builder $query): void
    {
        $query->whereNotNull('confirmed_at');
    }

    public function unsubscribeUrl(): string
    {
        return route('status-pages.unsubscribe', ['token' => $this->token]);
    }
}
