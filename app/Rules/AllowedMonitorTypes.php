<?php

namespace App\Rules;

use App\Enums\MonitorType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Server-side guard for plan-gated monitor types. Disabled buttons in the browser are only a hint;
 * a crafted request with a locked type is rejected here.
 */
class AllowedMonitorTypes implements ValidationRule
{
    /**
     * @param  list<string>  $allowedTypes
     */
    public function __construct(private array $allowedTypes) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ((array) $value as $type) {
            $type = $type instanceof MonitorType ? $type->value : (string) $type;

            if (! in_array($type, $this->allowedTypes, true)) {
                $label = MonitorType::tryFrom($type)?->label() ?? $type;
                $fail("{$label} monitoring is not included in your current plan.");
            }
        }
    }
}
