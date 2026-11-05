<?php

namespace App\Services\Settings;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SystemSettingsService
{
    public const CACHE_KEY = 'sitevaulta.system_settings.all';

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        try {
            $all = self::allGrouped();

            return $all[$group][$key] ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function allGrouped(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            if (! Schema::hasTable('system_settings')) {
                return [];
            }

            $grouped = [];
            foreach (SystemSetting::all() as $setting) {
                $grouped[$setting->group][$setting->key] = $setting->getTypedValue();
            }

            return $grouped;
        });
    }

    public static function set(
        string $group,
        string $key,
        mixed $value,
        string $type = 'string',
        bool $isEncrypted = false,
        bool $isPublic = false,
        ?string $label = null,
        ?string $helperText = null,
        ?int $updatedBy = null
    ): SystemSetting {
        $setting = SystemSetting::firstOrNew([
            'group' => $group,
            'key' => $key,
        ]);

        $setting->type = $type;
        $setting->is_encrypted = $isEncrypted;
        $setting->is_public = $isPublic;
        if ($label !== null) {
            $setting->label = $label;
        } elseif (! $setting->exists) {
            $setting->label = ucwords(str_replace('_', ' ', $key));
        }
        if ($helperText !== null) {
            $setting->helper_text = $helperText;
        }
        if ($updatedBy !== null) {
            $setting->updated_by = $updatedBy;
        }

        $setting->setTypedValue($value);
        $setting->save();

        self::flushCache();

        return $setting;
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function injectRuntimeConfig(): void
    {
        try {
            if (! Schema::hasTable('system_settings')) {
                return;
            }

            $appName = self::get('general', 'app_name');
            if ($appName) {
                config(['app.name' => $appName]);
            }

            $timezone = self::get('general', 'default_timezone');
            if ($timezone) {
                config(['app.timezone' => $timezone]);
            }

            // Dynamic SMTP injection
            $smtpHost = self::get('smtp', 'host');
            if (! empty($smtpHost)) {
                config([
                    'mail.default' => self::get('smtp', 'mailer', 'smtp'),
                    'mail.mailers.smtp.host' => $smtpHost,
                    'mail.mailers.smtp.port' => (int) self::get('smtp', 'port', 587),
                    'mail.mailers.smtp.username' => self::get('smtp', 'username'),
                    'mail.mailers.smtp.password' => self::get('smtp', 'password'),
                    'mail.mailers.smtp.encryption' => self::get('smtp', 'encryption', 'tls'),
                    'mail.from.address' => self::get('smtp', 'from_address', config('mail.from.address')),
                    'mail.from.name' => self::get('smtp', 'from_name', $appName ?: config('app.name')),
                ]);
            }
        } catch (\Throwable) {
            // Safely ignore during early boot or migrations
        }
    }
}

