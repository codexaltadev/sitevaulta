<?php

namespace App\Services\Settings;

use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Illuminate\Support\Facades\Cache;

class WorkspaceSettingsService
{
    public static function cacheKey(int $workspaceId): string
    {
        return "sitevaulta.workspace_settings.{$workspaceId}";
    }

    public static function get(Workspace|int $workspace, string $group, string $key, mixed $default = null): mixed
    {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        try {
            $all = Cache::rememberForever(self::cacheKey($workspaceId), function () use ($workspaceId): array {
                $grouped = [];
                foreach (WorkspaceSetting::where('workspace_id', $workspaceId)->get() as $setting) {
                    $grouped[$setting->group][$setting->key] = $setting->getTypedValue();
                }

                return $grouped;
            });

            if (isset($all[$group][$key])) {
                return $all[$group][$key];
            }
        } catch (\Throwable) {
            // Fall through to default
        }

        return $default;
    }

    public static function set(
        Workspace|int $workspace,
        string $group,
        string $key,
        mixed $value,
        string $type = 'string'
    ): WorkspaceSetting {
        $workspaceId = $workspace instanceof Workspace ? $workspace->id : $workspace;

        $serialized = match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => $value !== null ? (string) $value : null,
        };

        $setting = WorkspaceSetting::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'group' => $group,
                'key' => $key,
            ],
            [
                'value' => $serialized,
                'type' => $type,
            ]
        );

        Cache::forget(self::cacheKey($workspaceId));

        return $setting;
    }

    public static function initializeDefaultsForWorkspace(Workspace $workspace): void
    {
        $defaultExcludes = SystemSettingsService::get('backup_engine', 'default_exclude_paths', [
            '.git',
            'node_modules',
            'vendor',
            'storage/logs/*.log',
            '*.tmp',
        ]);

        self::set($workspace, 'regional', 'timezone', $workspace->timezone ?: SystemSettingsService::get('general', 'default_timezone', 'Asia/Dhaka'), 'string');
        self::set($workspace, 'regional', 'currency', $workspace->billing_currency ?: SystemSettingsService::get('general', 'default_currency', 'BDT'), 'string');
        self::set($workspace, 'regional', 'date_format', 'd M Y, H:i', 'string');
        self::set($workspace, 'security', 'require_2fa', (bool) SystemSettingsService::get('security', 'enforce_2fa', false), 'boolean');
        self::set($workspace, 'backup_defaults', 'default_exclude_paths', $defaultExcludes, 'json');
        self::set($workspace, 'backup_defaults', 'default_retention_days', SystemSettingsService::get('backup_engine', 'default_retention_days', 7), 'integer');
        self::set($workspace, 'backup_defaults', 'default_enable_encryption', true, 'boolean');
        self::set($workspace, 'backup_defaults', 'default_download_mode', 'one_time_token', 'string');
        self::set($workspace, 'notifications', 'notify_on_failure', true, 'boolean');
        self::set($workspace, 'notifications', 'notify_on_anomaly', true, 'boolean');
        self::set($workspace, 'notifications', 'notify_on_success', false, 'boolean');
        self::set($workspace, 'notifications', 'quiet_hours_enabled', false, 'boolean');
        self::set($workspace, 'notifications', 'quiet_hours_start', '23:00', 'string');
        self::set($workspace, 'notifications', 'quiet_hours_end', '07:00', 'string');
    }
}
