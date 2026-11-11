<?php

namespace App\Rules;

use App\Models\Workspace;
use App\Services\Monitoring\HostOwnershipVerifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Port monitoring is a scan; it is only allowed against hosts the workspace has proven it controls.
 */
class OwnedMonitorHost implements ValidationRule
{
    /**
     * @param  string|null  $host  the host to check; when null the validated value itself is the host
     */
    public function __construct(private ?Workspace $workspace, private ?string $host = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = $this->host ?? (is_string($value) ? $value : null);

        if (blank($host)) {
            return;
        }

        if ($this->workspace === null || ! app(HostOwnershipVerifier::class)->ownership($this->workspace, $host)['owned']) {
            $fail("Port checks are only allowed on hosts you own. Verify {$host} under Uptime Monitoring → Verified domains, add it as a verified website, or connect it as a server first.");
        }
    }
}
