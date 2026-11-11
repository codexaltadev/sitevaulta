<?php

namespace App\Rules;

use App\Models\Monitor;
use App\Models\Website;
use App\Models\Workspace;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A domain or sub-domain may be added only once per workspace — once as a monitor, once as a backup
 * website. "www." is ignored (www.example.com = example.com); different sub-domains are different targets.
 */
class UniqueWorkspaceTarget implements ValidationRule
{
    public const MONITORS = 'monitors';

    public const WEBSITES = 'websites';

    public function __construct(
        private ?Workspace $workspace,
        private string $scope = self::MONITORS,
        private ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = Monitor::normalizeHost(is_string($value) ? $value : null);

        if ($host === null || $this->workspace === null) {
            return;
        }

        if ($this->scope === self::WEBSITES) {
            $duplicate = $this->workspace->websites()
                ->when($this->ignoreId, fn ($query) => $query->whereKeyNot($this->ignoreId))
                ->get(['id', 'name', 'domain', 'url'])
                ->first(fn (Website $website): bool => Monitor::normalizeHost($website->domain ?: $website->url) === $host);

            if ($duplicate !== null) {
                $fail("{$host} is already added to this workspace as \"{$duplicate->name}\".");
            }

            return;
        }

        $duplicate = $this->workspace->monitors()
            ->where('target_host', $host)
            ->when($this->ignoreId, fn ($query) => $query->whereKeyNot($this->ignoreId))
            ->first(['id', 'name']);

        if ($duplicate !== null) {
            $fail("{$host} is already monitored in this workspace (\"{$duplicate->name}\"). Open that monitor and add Ping, SSL, Domain, DNS or Port as extra checks instead.");
        }
    }
}
