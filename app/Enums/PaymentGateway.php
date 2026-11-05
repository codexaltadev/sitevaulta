<?php

namespace App\Enums;

use App\Services\Settings\SystemSettingsService;

enum PaymentGateway: string
{
    case None = 'none';
    case SslCommerz = 'sslcommerz';
    case Stripe = 'stripe';
    case PayPal = 'paypal';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None (Trial)',
            self::SslCommerz => 'SSLCOMMERZ (bKash / Nagad / Cards - BDT)',
            self::Stripe => 'Stripe (International Cards - USD)',
            self::PayPal => 'PayPal (USD)',
            self::Manual => 'Manual / Admin Override',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }

    /**
     * Returns dynamically enabled customer checkout gateways from SystemSettingsService.
     *
     * @return array<int, self>
     */
    public static function enabledGateways(): array
    {
        $enabled = [];

        if ((bool) SystemSettingsService::get('payment_sslcommerz', 'enabled', true)) {
            $enabled[] = self::SslCommerz;
        }

        if ((bool) SystemSettingsService::get('payment_stripe', 'enabled', true)) {
            $enabled[] = self::Stripe;
        }

        if ((bool) SystemSettingsService::get('payment_paypal', 'enabled', true)) {
            $enabled[] = self::PayPal;
        }

        if (empty($enabled)) {
            $enabled = [self::SslCommerz, self::Stripe, self::PayPal];
        }

        return $enabled;
    }

    public static function enabledGatewayOptions(): array
    {
        return collect(self::enabledGateways())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
