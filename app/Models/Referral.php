<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    protected $fillable = [
        'referrer_workspace_id',
        'referred_workspace_id',
        'referred_user_id',
        'referral_code_used',
        'status',
        'signup_bonus_credits',
        'purchase_reward_credits',
        'referee_bonus_credits',
        'converted_payment_id',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'signup_bonus_credits' => 'integer',
            'purchase_reward_credits' => 'integer',
            'referee_bonus_credits' => 'integer',
            'converted_at' => 'datetime',
        ];
    }

    public function referrerWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'referrer_workspace_id');
    }

    public function referredWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'referred_workspace_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function convertedPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'converted_payment_id');
    }
}

