<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailPackage extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'sort_order',
        'credits_amount',
        'bonus_credits',
        'price_bdt',
        'price_usd',
        'is_featured',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'credits_amount' => 'integer',
            'bonus_credits' => 'integer',
            'price_bdt' => 'decimal:2',
            'price_usd' => 'decimal:2',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function totalCredits(): int
    {
        return (int) $this->credits_amount + (int) $this->bonus_credits;
    }

    public function getPriceForCurrency(string $currency = 'BDT'): float
    {
        return strtoupper($currency) === 'USD'
            ? (float) $this->price_usd
            : (float) $this->price_bdt;
    }
}

