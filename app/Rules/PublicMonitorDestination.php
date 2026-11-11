<?php

namespace App\Rules;

use App\Services\Monitoring\DestinationGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects monitor targets that point at loopback, private, link-local or reserved addresses, so the
 * platform cannot be used to probe its own internal network. The check engine re-validates every run.
 */
class PublicMonitorDestination implements ValidationRule
{
    public function __construct(private bool $isUrl = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $guard = app(DestinationGuard::class);
        $verdict = $this->isUrl ? $guard->inspectUrl((string) $value) : $guard->inspectHost((string) $value);

        if (! $verdict['allowed']) {
            $fail((string) $verdict['reason']);
        }
    }
}
