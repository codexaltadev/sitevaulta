<?php

namespace App\Models;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'workspace_id',
        'subscription_id',
        'invoice_number',
        'gateway',
        'gateway_transaction_id',
        'idempotency_key',
        'currency',
        'amount',
        'discount_amount',
        'tax_amount',
        'status',
        'gateway_payload',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'gateway' => PaymentGateway::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'gateway_payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}

