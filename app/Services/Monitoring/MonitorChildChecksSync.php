<?php

namespace App\Services\Monitoring;

use App\Enums\MonitorType;
use App\Models\Monitor;

/**
 * Extra checks ticked on a monitor (Ping, SSL expiry, Domain expiry, DNS) are stored as child monitors
 * that share the parent's target and alert rules, so the existing engine, incidents and maintenance
 * windows apply to them unchanged while the UI reports them together with the parent.
 */
class MonitorChildChecksSync
{
    /**
     * @var list<string>
     */
    public const CHILD_TYPES = ['ping', 'ssl', 'domain', 'dns', 'port'];

    /**
     * Child check types offered for a primary monitor type (none for heartbeats, never the primary type itself).
     *
     * @return list<string>
     */
    public static function childTypesFor(MonitorType $primaryType): array
    {
        if ($primaryType->isPassive()) {
            return [];
        }

        return array_values(array_diff(self::CHILD_TYPES, [$primaryType->value]));
    }

    /**
     * Only a main (top-level) monitor can own extra checks; null means a monitor that is being created.
     */
    public static function canOwnExtraChecks(?Monitor $monitor): bool
    {
        return $monitor === null || $monitor->parent_id === null;
    }

    /**
     * Hostname the extra checks run against, taken from the parent's URL or host.
     */
    public static function hostFor(MonitorType $primaryType, ?string $url, ?string $host): ?string
    {
        $resolved = $primaryType->usesUrl() ? parse_url((string) $url, PHP_URL_HOST) : $host;

        return filled($resolved) ? mb_strtolower((string) $resolved) : null;
    }

    /**
     * The registered domain whose expiry matters ("elearn.daffodilvarsity.edu.bd" → "daffodilvarsity.edu.bd").
     */
    public static function suggestedDomainFor(?string $host): ?string
    {
        return RegistrableDomain::of($host);
    }

    /**
     * Form state for an existing parent: which extra checks are on and their settings.
     *
     * @return array{extra_checks: list<string>, extras: array<string, array<string, mixed>>}
     */
    public function formStateFor(Monitor $parent): array
    {
        $children = $parent->children()->get()->keyBy(fn (Monitor $child): string => $child->type->value);

        return [
            'extra_checks' => $children->keys()->values()->all(),
            'extras' => [
                'ssl' => [
                    'port' => $children['ssl']->port ?? null,
                    'expiry_threshold_days' => $children['ssl']->expiry_threshold_days ?? 14,
                ],
                'domain' => [
                    'host' => $children['domain']->host ?? null,
                    'expiry_threshold_days' => $children['domain']->expiry_threshold_days ?? 30,
                    'manual_expires_at' => isset($children['domain']) ? $children['domain']->manual_expires_at?->toDateString() : null,
                ],
                'dns' => [
                    'dns_record_types' => isset($children['dns']) ? $children['dns']->dnsRecordTypes() : ['A'],
                ],
                'port' => [
                    'ports' => isset($children['port']) ? array_map('strval', $children['port']->portList()) : [],
                ],
            ],
        ];
    }

    /**
     * Number of extra checks that would be newly created (used for the plan's monitor quota).
     *
     * @param  list<string>  $enabledTypes
     */
    public function newChildCount(?Monitor $parent, array $enabledTypes): int
    {
        $existing = $parent?->children()->pluck('type')->map(fn (MonitorType $type): string => $type->value)->all() ?? [];

        return count(array_diff($enabledTypes, $existing));
    }

    /**
     * Creates, updates and removes child checks so they match the ticked extra checks.
     *
     * @param  list<string>  $enabledTypes
     * @param  array<string, array<string, mixed>>  $settings
     */
    public function sync(Monitor $parent, array $enabledTypes, array $settings): void
    {
        // Extra checks belong to one main monitor; a check never owns checks of its own.
        if (! self::canOwnExtraChecks($parent)) {
            return;
        }

        $allowedChildTypes = self::childTypesFor($parent->type);
        $enabledTypes = array_values(array_intersect($enabledTypes, $allowedChildTypes));
        $host = self::hostFor($parent->type, $parent->url, $parent->host);
        $children = $parent->children()->get()->keyBy(fn (Monitor $child): string => $child->type->value);

        foreach ($children as $type => $child) {
            if (! in_array($type, $enabledTypes, true)) {
                $child->delete();
            }
        }

        if ($host === null) {
            return;
        }

        foreach ($enabledTypes as $type) {
            $attributes = $this->childAttributes($parent, MonitorType::from($type), $host, $settings[$type] ?? []);
            $existing = $children[$type] ?? null;

            if ($existing !== null) {
                $existing->update($attributes);

                continue;
            }

            $parent->children()->create($attributes + [
                'workspace_id' => $parent->workspace_id,
                'type' => $type,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function childAttributes(Monitor $parent, MonitorType $type, string $host, array $settings): array
    {
        $shared = [
            'name' => "{$parent->name} · ".match ($type) {
                MonitorType::Ssl => 'SSL',
                MonitorType::Domain => 'Domain',
                MonitorType::Dns => 'DNS',
                MonitorType::Port => 'Ports',
                default => 'Ping',
            },
            'url' => null,
            'timeout_seconds' => min(10, $parent->timeout_seconds),
            'alert_delay_minutes' => $parent->alert_delay_minutes,
            'alert_repeat_minutes' => $parent->alert_repeat_minutes,
            'alert_channel_ids' => $parent->alert_channel_ids,
            'notify_workspace_members' => $parent->notify_workspace_members,
        ];

        return $shared + match ($type) {
            MonitorType::Ssl => [
                'host' => $host,
                'port' => filled($settings['port'] ?? null) ? (int) $settings['port'] : null,
                'expiry_threshold_days' => (int) ($settings['expiry_threshold_days'] ?? 14),
                'interval_seconds' => 86400,
            ],
            MonitorType::Domain => [
                'host' => filled($settings['host'] ?? null) ? mb_strtolower(trim((string) $settings['host'])) : self::suggestedDomainFor($host),
                'expiry_threshold_days' => (int) ($settings['expiry_threshold_days'] ?? 30),
                'manual_expires_at' => filled($settings['manual_expires_at'] ?? null) ? $settings['manual_expires_at'] : null,
                'interval_seconds' => 86400,
            ],
            MonitorType::Port => [
                'host' => $host,
                'ports' => Monitor::normalizePorts((array) ($settings['ports'] ?? [])),
                'interval_seconds' => max(300, $parent->interval_seconds),
            ],
            MonitorType::Dns => [
                'host' => $host,
                'dns_record_types' => array_values((array) ($settings['dns_record_types'] ?? ['A'])) ?: ['A'],
                'dns_record_type' => ((array) ($settings['dns_record_types'] ?? ['A']))[0] ?? 'A',
                'interval_seconds' => max(3600, $parent->interval_seconds),
            ],
            default => [
                'host' => $host,
                'interval_seconds' => $parent->interval_seconds,
            ],
        };
    }
}
